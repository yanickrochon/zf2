<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Mvc
 * @subpackage View
 * @copyright  Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

namespace Zend\Mvc\View;

use ArrayAccess;
use Traversable;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zend\Mvc\ApplicationInterface;
use Zend\Mvc\Exception;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ConfigurationInterface;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ArrayUtils;
use Zend\View\HelperPluginManager as ViewHelperManager;
use Zend\View\Renderer\PhpRenderer as ViewPhpRenderer;
use Zend\View\Resolver as ViewResolver;
use Zend\View\Strategy\PhpRendererStrategy;
use Zend\View\View;

/**
 * Prepares the view layer
 *
 * Instantiates and configures all classes related to the view layer, including
 * the renderer (and its associated resolver(s) and helper manager), the view
 * object (and its associated rendering strategies), and the various MVC
 * strategies and listeners.
 *
 * Defines and manages the following services:
 *
 * - ViewHelperManager (also aliased to Zend\View\HelperPluginManager and ViewHelperBroker)
 * - ViewTemplateMapResolver (also aliased to Zend\View\Resolver\TemplateMapResolver)
 * - ViewTemplatePathStack (also aliased to Zend\View\Resolver\TemplatePathStack)
 * - ViewResolver (also aliased to Zend\View\Resolver\AggregateResolver and ResolverInterface)
 * - ViewRenderer (also aliased to Zend\View\Renderer\PhpRenderer and RendererInterface)
 * - ViewPhpRendererStrategy (also aliased to Zend\View\Strategy\PhpRendererStrategy)
 * - View (also aliased to Zend\View\View)
 * - DefaultRenderingStrategy (also aliased to Zend\Mvc\View\DefaultRenderingStrategy)
 * - ExceptionStrategy (also aliased to Zend\Mvc\View\ExceptionStrategy)
 * - RouteNotFoundStrategy (also aliased to Zend\Mvc\View\RouteNotFoundStrategy and 404Strategy)
 * - ViewModel
 *
 * @category   Zend
 * @package    Zend_Mvc
 * @subpackage View
 * @copyright  Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class ViewManager implements ListenerAggregateInterface
{
    /**
     * @var \Zend\Stdlib\CallbackHandler[]
     */
    protected $listeners = array();

    /**
     * @var object application configuration service
     */
    protected $config;

    /**
     * @var \Zend\ServiceManager\ServiceManager
     */
    protected $services;

    /**@+
     * Various properties representing strategies and objects instantiated and
     * configured by the view manager
     */
    protected $exceptionStrategy;
    protected $helperManager;
    protected $mvcRenderingStrategy;
    protected $renderer;
    protected $rendererStrategy;
    protected $resolver;
    protected $routeNotFoundStrategy;
    protected $view;
    protected $viewModel;
    /**@-*/

    /**
     * Attach the aggregate to the specified event manager
     *
     * @param  EventManagerInterface $events
     * @return void
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach('bootstrap', array($this, 'onBootstrap'), 10000);
    }

    /**
     * Detach aggregate listeners from the specified event manager
     *
     * @param  EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    /**
     * Prepares the view layer
     *
     * @param  $event
     * @return void
     */
    public function onBootstrap($event)
    {
        $application  = $event->getApplication();
        $services     = $application->getServiceManager();
        $config       = $services->get('Configuration');
        $events       = $application->events();
        $sharedEvents = $events->getSharedManager();

        $this->config   = isset($config['view_manager']) && (is_array($config['view_manager']) || $config['view_manager'] instanceof ArrayAccess)
                        ? $config['view_manager']
                        : array();
        $this->services = $services;
        $this->event    = $event;

        $routeNotFoundStrategy   = $this->getRouteNotFoundStrategy();
        $exceptionStrategy       = $this->getExceptionStrategy();
        $mvcRenderingStrategy    = $this->getMvcRenderingStrategy();
        $createViewModelListener = new CreateViewModelListener();
        $injectTemplateListener  = new InjectTemplateListener();
        $injectViewModelListener = new InjectViewModelListener();

        $this->registerMvcRenderingStrategies($events);
        $this->registerViewStrategies();

        $events->attach($routeNotFoundStrategy);
        $events->attach($exceptionStrategy);
        $events->attach(MvcEvent::EVENT_DISPATCH_ERROR, array($injectViewModelListener, 'injectViewModel'), -100);
        $events->attach($mvcRenderingStrategy);

        $sharedEvents->attach('Zend\Stdlib\DispatchableInterface', MvcEvent::EVENT_DISPATCH, array($createViewModelListener, 'createViewModelFromArray'), -80);
        $sharedEvents->attach('Zend\Stdlib\DispatchableInterface', MvcEvent::EVENT_DISPATCH, array($routeNotFoundStrategy, 'prepareNotFoundViewModel'), -90);
        $sharedEvents->attach('Zend\Stdlib\DispatchableInterface', MvcEvent::EVENT_DISPATCH, array($createViewModelListener, 'createViewModelFromNull'), -80);
        $sharedEvents->attach('Zend\Stdlib\DispatchableInterface', MvcEvent::EVENT_DISPATCH, array($injectTemplateListener, 'injectTemplate'), -90);
        $sharedEvents->attach('Zend\Stdlib\DispatchableInterface', MvcEvent::EVENT_DISPATCH, array($injectViewModelListener, 'injectViewModel'), -100);
    }

    /**
     * Instantiates and configures the renderer's helper manager
     *
     * @return ViewHelperManager
     */
    public function getHelperManager()
    {
        if ($this->helperManager) {
            return $this->helperManager;
        }

        $this->helperManager = new ViewHelperManager();

        // Setup additional helpers
        $map = array();
        if (isset($this->config['helper_map'])) {
            $map = $this->config['helper_map'];
        }
        if (!in_array('Zend\Form\View\HelperConfiguration', $map)) {
            array_unshift($map, 'Zend\Form\View\HelperConfiguration');
        }
        foreach ($map as $key => $service) {
            if ((!is_string($key) || is_numeric($key))
                && class_exists($service)
            ) {
                $config = new $service;
                if (!$config instanceof ConfigurationInterface) {
                    throw new Exception\RuntimeException(sprintf(
                        'Invalid helper configuration map provided; received "%s", expected class implementing %s',
                        $service,
                        'Zend\ServiceManager\ConfigurationInterface'
                    ));
                }
                $config->configureServiceManager($this->helperManager);
                continue;
            }
            $this->helperManager->setInvokableClass($key, $service);
        }

        // Seed with service manager
        if ($this->services instanceof ServiceManager) {
            $this->helperManager->addPeeringServiceManager($this->services, ServiceManager::SCOPE_PARENT);
        }

        // Configure URL view helper with router
        $this->helperManager->setFactory('Zend\View\Helper\Url', function($sm) {
            $urlHelper = new \Zend\View\Helper\Url;
            $urlHelper->setRouter($sm->get('Router'));
            return $urlHelper;
        });
        $this->helperManager->setAlias('url', 'Zend\View\Helper\Url');

        $config = $this->config;

        // Configure basePath view helper with base path from configuration, if available
        $this->helperManager->setFactory('Zend\View\Helper\BasePath', function($sm) use($config) {
            $basePathHelper = new \Zend\View\Helper\BasePath;
            if (isset($config['base_path'])) {
                $basePath = $config['base_path'];
            } else {
                $basePath = $sm->get('Request')->getBasePath();
            }
            $basePathHelper->setBasePath($basePath);
            return $basePathHelper;
        });
        $this->helperManager->setAlias('basepath', 'Zend\View\Helper\BasePath');

        // Configure doctype view helper with doctype from configuration, if available
        $this->helperManager->setFactory('Zend\View\Helper\Doctype', function($sm) use($config) {
            $doctypeHelper = new \Zend\View\Helper\Doctype;
            if (isset($config['doctype'])) {
                $doctypeHelper->setDoctype($config['doctype']);
            }
            return $doctypeHelper;
        });
        $this->helperManager->setAlias('doctype', 'Zend\View\Helper\Doctype');

        $this->services->setService('ViewHelperManager', $this->helperManager);
        $this->services->setAlias('ViewHelperBroker', 'ViewHelperManager');
        $this->services->setAlias('Zend\View\HelperPluginManager', 'ViewHelperManager');

        return $this->helperManager;
    }

    /**
     * Instantiates and configures the renderer's resolver
     *
     * @return ViewAggregateResolver
     */
    public function getResolver()
    {
        if ($this->resolver) {
            return $this->resolver;
        }

        $map = array();
        if (isset($this->config['template_map'])) {
            $map = $this->config['template_map'];
        }
        $templateMapResolver = new ViewResolver\TemplateMapResolver($map);

        $stack = array();
        if (isset($this->config['template_path_stack'])) {
            $stack = $this->config['template_path_stack'];
        }
        $templatePathStack = new ViewResolver\TemplatePathStack();
        $templatePathStack->addPaths($stack);

        $this->resolver = new ViewResolver\AggregateResolver();
        $this->resolver->attach($templateMapResolver);
        $this->resolver->attach($templatePathStack);

        $this->services->setService('ViewTemplateMapResolver', $templateMapResolver);
        $this->services->setService('ViewTemplatePathStack', $templatePathStack);
        $this->services->setService('ViewResolver', $this->resolver);

        $this->services->setAlias('Zend\View\Resolver\TemplateMapResolver', 'ViewTemplateMapResolver');
        $this->services->setAlias('Zend\View\Resolver\TemplatePathStack', 'ViewTemplatePathStack');
        $this->services->setAlias('Zend\View\Resolver\AggregateResolver', 'ViewResolver');
        $this->services->setAlias('Zend\View\Resolver\ResolverInterface', 'ViewResolver');

        return $this->resolver;
    }

    /**
     * Instantiates and configures the renderer
     *
     * @return ViewPhpRenderer
     */
    public function getRenderer()
    {
        if ($this->renderer) {
            return $this->renderer;
        }

        $this->renderer = new ViewPhpRenderer;
        $this->renderer->setHelperPluginManager($this->getHelpermanager());
        $this->renderer->setResolver($this->getResolver());

        $model       = $this->getViewModel();
        $modelHelper = $this->renderer->plugin('view_model');
        $modelHelper->setRoot($model);

        $this->services->setService('ViewRenderer', $this->renderer);
        $this->services->setAlias('Zend\View\Renderer\PhpRenderer', 'ViewRenderer');
        $this->services->setAlias('Zend\View\Renderer\RendererInterface', 'ViewRenderer');

        return $this->renderer;
    }

    /**
     * Instantiates and configures the renderer strategy for the view
     *
     * @return PhpRendererStrategy
     */
    public function getRendererStrategy()
    {
        if ($this->rendererStrategy) {
            return $this->rendererStrategy;
        }

        $this->rendererStrategy = new PhpRendererStrategy(
            $this->getRenderer()
        );

        $this->services->setService('ViewPhpRendererStrategy', $this->rendererStrategy);
        $this->services->setAlias('Zend\View\Strategy\PhpRendererStrategy', 'ViewPhpRendererStrategy');

        return $this->rendererStrategy;
    }

    /**
     * Instantiates and configures the view
     *
     * @return View
     */
    public function getView()
    {
        if ($this->view) {
            return $this->view;
        }

        $this->view = new View();
        $this->view->setEventManager($this->services->get('EventManager'));
        $this->view->events()->attach($this->getRendererStrategy());

        $this->services->setService('View', $this->view);
        $this->services->setAlias('Zend\View\View', 'View');

        return $this->view;
    }

    /**
     * Retrieves the layout template name from the configuration
     *
     * @return string
     */
    public function getLayoutTemplate()
    {
        $layout = 'layout/layout';
        if (isset($this->config['layout'])) {
            $layout = $this->config['layout'];
        }
        return $layout;
    }

    /**
     * Instantiates and configures the default MVC rendering strategy
     *
     * @return DefaultRenderingStrategy
     */
    public function getMvcRenderingStrategy()
    {
        if ($this->mvcRenderingStrategy) {
            return $this->mvcRenderingStrategy;
        }

        $this->mvcRenderingStrategy = new DefaultRenderingStrategy($this->getView());
        $this->mvcRenderingStrategy->setLayoutTemplate($this->getLayoutTemplate());

        $this->services->setService('DefaultRenderingStrategy', $this->mvcRenderingStrategy);
        $this->services->setAlias('Zend\Mvc\View\DefaultRenderingStrategy', 'DefaultRenderingStrategy');

        return $this->mvcRenderingStrategy;
    }

    /**
     * Instantiates and configures the exception strategy
     *
     * @return ExceptionStrategy
     */
    public function getExceptionStrategy()
    {
        if ($this->exceptionStrategy) {
            return $this->exceptionStrategy;
        }

        $this->exceptionStrategy = new ExceptionStrategy();

        $displayExceptions = false;
        $exceptionTemplate = 'error';

        if (isset($this->config['display_exceptions'])) {
            $displayExceptions = $this->config['display_exceptions'];
        }
        if (isset($this->config['exception_template'])) {
            $exceptionTemplate = $this->config['exception_template'];
        }

        $this->exceptionStrategy->setDisplayExceptions($displayExceptions);
        $this->exceptionStrategy->setExceptionTemplate($exceptionTemplate);

        $this->services->setService('ExceptionStrategy', $this->exceptionStrategy);
        $this->services->setAlias('Zend\Mvc\View\ExceptionStrategy', 'ExceptionStrategy');

        return $this->exceptionStrategy;
    }

    /**
     * Instantiates and configures the "route not found", or 404, strategy
     *
     * @return RouteNotFoundStrategy
     */
    public function getRouteNotFoundStrategy()
    {
        if ($this->routeNotFoundStrategy) {
            return $this->routeNotFoundStrategy;
        }

        $this->routeNotFoundStrategy = new RouteNotFoundStrategy();

        $displayNotFoundReason = false;
        $notFoundTemplate      = '404';

        if (isset($this->config['display_not_found_reason'])) {
            $displayNotFoundReason = $this->config['display_not_found_reason'];
        }
        if (isset($this->config['not_found_template'])) {
            $notFoundTemplate = $this->config['not_found_template'];
        }

        $this->routeNotFoundStrategy->setDisplayNotFoundReason($displayNotFoundReason);
        $this->routeNotFoundStrategy->setNotFoundTemplate($notFoundTemplate);

        $this->services->setService('RouteNotFoundStrategy', $this->routeNotFoundStrategy);
        $this->services->setAlias('Zend\Mvc\View\RouteNotFoundStrategy', 'RouteNotFoundStrategy');
        $this->services->setAlias('404Strategy', 'RouteNotFoundStrategy');

        return $this->routeNotFoundStrategy;
    }

    /**
     * Configures the MvcEvent view model to ensure it has the template injected
     *
     * @return \Zend\Mvc\View\Model\ModelInterface
     */
    public function getViewModel()
    {
        if ($this->viewModel) {
            return $this->viewModel;
        }

        $this->viewModel = $model = $this->event->getViewModel();
        $model->setTemplate($this->getLayoutTemplate());

        return $this->viewModel;
    }

    /**
     * Register additional mvc rendering strategies
     *
     * If there is a "mvc_strategies" key of the view manager configuration, loop
     * through it. Pull each as a service fromt the service manager, and, if it
     * is a ListenerAggregate, attach it to the view, at priority 100. This
     * latter allows each to trigger before the default mvc rendering strategy,
     * and for them to trigger in the order they are registered.
     */
    protected function registerMvcRenderingStrategies(EventManagerInterface $events)
    {
        if (!isset($this->config['mvc_strategies'])) {
            return;
        }
        $mvcStrategies = $this->config['mvc_strategies'];
        if (is_string($mvcStrategies)) {
            $mvcStrategies = array($mvcStrategies);
        }
        if (!is_array($mvcStrategies) && !$mvcStrategies instanceof Traversable) {
            return;
        }

        foreach ($mvcStrategies as $mvcStrategy) {
            if (!is_string($mvcStrategy)) {
                continue;
            }

            $listener = $this->services->get($mvcStrategy);
            if ($listener instanceof ListenerAggregateInterface) {
                $events->attach($listener, 100);
            }
        }
    }

    /**
     * Register additional view strategies
     *
     * If there is a "strategies" key of the view manager configuration, loop
     * through it. Pull each as a service from the service manager, and, if it
     * is a ListenerAggregate, attach it to the view, at priority 100. This
     * latter allows each to trigger before the default strategy, and for them
     * to trigger in the order they are registered.
     *
     * @return void
     */
    protected function registerViewStrategies()
    {
        if (!isset($this->config['strategies'])) {
            return;
        }
        $strategies = $this->config['strategies'];
        if (is_string($strategies)) {
            $strategies = array($strategies);
        }
        if (!is_array($strategies) && !$strategies instanceof Traversable) {
            return;
        }

        $view = $this->getView();

        foreach ($strategies as $strategy) {
            if (!is_string($strategy)) {
                continue;
            }

            $listener = $this->services->get($strategy);
            if ($listener instanceof ListenerAggregateInterface) {
                $view->events()->attach($listener, 100);
            }
        }
    }
}
