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
 * @package    Zend_Mail
 * @subpackage Transport
 * @copyright  Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

namespace Zend\Mail\Transport;

use Zend\Mail\Address;
use Zend\Mail\Headers;
use Zend\Mail\Message;
use Zend\Mail\Protocol;
use Zend\Mail\Protocol\Exception as ProtocolException;

/**
 * SMTP connection object
 *
 * Loads an instance of Zend\Mail\Protocol\Smtp and forwards smtp transactions
 *
 * @category   Zend
 * @package    Zend_Mail
 * @subpackage Transport
 * @copyright  Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Smtp implements TransportInterface
{
    /**
     * @var SmtpOptions
     */
    protected $options;

    /**
     * @var Protocol\Smtp
     */
    protected $connection;
    
    /**
     * @var boolean
     */
    protected $autoDisconnect = true;

    /**
     * @var Protocol\SmtpPluginManager
     */
    protected $plugins;

    /**
     * Constructor.
     *
     * @param  SmtpOptions $options Optional
     */
    public function __construct(SmtpOptions $options = null)
    {
        if (!$options instanceof SmtpOptions) {
            $options = new SmtpOptions();
        }
        $this->setOptions($options);
    }

    /**
     * Set options
     *
     * @param  SmtpOptions $options
     * @return Smtp
     */
    public function setOptions(SmtpOptions $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Get options
     * 
     * @return SmtpOptions
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set plugin manager for obtaining SMTP protocol connection
     *
     * @param  Protocol\SmtpPluginManager $plugins
     * @throws Exception\InvalidArgumentException
     * @return Smtp
     */
    public function setPluginManager(Protocol\SmtpPluginManager $plugins)
    {
        $this->plugins = $plugins;
        return $this;
    }
    
    /**
     * Get plugin manager for loading SMTP protocol connection
     *
     * @return Protocol\SmtpPluginManager
     */
    public function getPluginManager()
    {
        if (null === $this->plugins) {
            $this->setPluginManager(new Protocol\SmtpPluginManager());
        }
        return $this->plugins;
    }

    /**
     * Set the automatic disconnection when destruct
     * 
     * @param  boolean $flag
     * @return Smtp
     */
    public function setAutoDisconnect($flag) 
    {
        $this->autoDisconnect = (bool) $flag;
        return $this;
    }

    /**
     * Get the automatic disconnection value
     * 
     * @return boolean
     */
    public function getAutoDisconnect()
    {
        return $this->autoDisconnect;
    }

    /**
     * Return an SMTP connection
     * 
     * @param  string $name 
     * @param  array|null $options 
     * @return Protocol\Smtp
     */
    public function plugin($name, array $options = null)
    {
        return $this->getPluginManager()->get($name, $options);
    }

    /**
     * Class destructor to ensure all open connections are closed
     */
    public function __destruct()
    {
        if ($this->connection instanceof Protocol\Smtp) {
            try {
                $this->connection->quit();
            } catch (ProtocolException\ExceptionInterface $e) {
                // ignore
            }
            if ($this->autoDisconnect) {
                $this->connection->disconnect();
            }    
        }
    }

    /**
     * Sets the connection protocol instance
     *
     * @param Protocol\AbstractProtocol $connection
     */
    public function setConnection(Protocol\AbstractProtocol $connection)
    {
        $this->connection = $connection;
    }


    /**
     * Gets the connection protocol instance
     *
     * @return Protocol\Smtp
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Disconnect the connection protocol instance
     * 
     * @return void
     */
    public function disconnect()
    {
        if (!empty($this->connection) && ($this->connection instanceof Protocol\Smtp)) {
            $this->connection->disconnect();
        }
    }

    /**
     * Send an email via the SMTP connection protocol
     *
     * The connection via the protocol adapter is made just-in-time to allow a
     * developer to add a custom adapter if required before mail is sent.
     *
     * @param Message $message
     */
    public function send(Message $message)
    {
        // If sending multiple messages per session use existing adapter
        $connection = $this->getConnection();

        if (!($connection instanceof Protocol\Smtp)) {
            // First time connecting
            $connection = $this->lazyLoadConnection();
        } else {
            // Reset connection to ensure reliable transaction
            $connection->rset();
        }

        // Prepare message
        $from       = $this->prepareFromAddress($message);
        $recipients = $this->prepareRecipients($message);
        $headers    = $this->prepareHeaders($message);
        $body       = $this->prepareBody($message);

        if ((count($recipients) == 0) && (!empty($headers) || !empty($body))) {
            throw new Exception\RuntimeException(  // Per RFC 2821 3.3 (page 18)
                sprintf(
                    '%s transport expects at least one recipient if the message has at least one header or body',
                    __CLASS__
                ));
        }

        // Set sender email address
        $connection->mail($from);

        // Set recipient forward paths
        foreach ($recipients as $recipient) {
            $connection->rcpt($recipient);
        }

        // Issue DATA command to client
        $connection->data($headers . Headers::EOL . $body);
    }

    /**
     * Retrieve email address for envelope FROM
     *
     * @param  Message $message
     * @throws Exception\RuntimeException
     * @return string
     */
    protected function prepareFromAddress(Message $message)
    {
        $sender = $message->getSender();
        if ($sender instanceof Address\AddressInterface) {
            return $sender->getEmail();
        }

        $from = $message->from();
        if (!count($from)) { // Per RFC 2822 3.6
            throw new Exception\RuntimeException(sprintf(
                '%s transport expects either a Sender or at least one From address in the Message; none provided',
                __CLASS__
            ));
        }

        $from->rewind();
        $sender = $from->current();
        return $sender->getEmail();
    }

    /**
     * Prepare array of email address recipients
     *
     * @param  Message $message
     * @return array
     */
    protected function prepareRecipients(Message $message)
    {
        $recipients = array();
        foreach ($message->to() as $address) {
            $recipients[] = $address->getEmail();
        }
        foreach ($message->cc() as $address) {
            $recipients[] = $address->getEmail();
        }
        foreach ($message->bcc() as $address) {
            $recipients[] = $address->getEmail();
        }
        $recipients = array_unique($recipients);
        return $recipients;
    }

    /**
     * Prepare header string from message
     *
     * @param  Message $message
     * @return string
     */
    protected function prepareHeaders(Message $message)
    {
        $headers = clone $message->headers();
        $headers->removeHeader('Bcc');
        return $headers->toString();
    }

    /**
     * Prepare body string from message
     *
     * @param  Message $message
     * @return string
     */
    protected function prepareBody(Message $message)
    {
        return $message->getBodyText();
    }

    /**
     * Lazy load the connection, and pass it helo
     * 
     * @return Protocol\Smtp
     */
    protected function lazyLoadConnection()
    {
        // Check if authentication is required and determine required class
        $options    = $this->getOptions();
        $host       = $options->getHost();
        $port       = $options->getPort();
        $config     = $options->getConnectionConfig();
        $connection = $this->plugin($options->getConnectionClass(), array($host, $port, $config));
        $this->connection = $connection;
        $this->connection->connect();
        $this->connection->helo($options->getName());
        return $this->connection;
    }
}
