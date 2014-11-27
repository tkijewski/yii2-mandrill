<?php
/**
 * Contains the Mailer class.
 * 
 * @link http://www.creationgears.com/
 * @copyright Copyright (c) 2014 Nicola Puddu
 * @license http://www.gnu.org/copyleft/gpl.html
 * @package nickcv/yii2-mandrill
 * @author Nicola Puddu <n.puddu@outlook.com>
 */

namespace nickcv\mandrill;

use yii\mail\BaseMailer;
use yii\base\InvalidConfigException;
use nickcv\mandrill\Message;
use Mandrill;
use Mandrill_Error;

/**
 * Mailer is the class that consuming the Message object sends emails thorugh
 * the Mandrill API.
 *
 * @author Nicola Puddu <n.puddu@outlook.com>
 * @version 1.0
 */
class Mailer extends BaseMailer
{

    const STATUS_SENT = 'sent';
    const STATUS_QUEUED = 'queued';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_REJECTED = 'rejected';
    const STATUS_INVALID = 'invalid';
    const LOG_CATEGORY = 'mandrill';

    /**
     * @var string Mandrill API key
     */
    private $_apikey;
    
    /**
     * @var string message default class name.
     */
    public $messageClass = 'nickcv\mandrill\Message';
    
    /**
     * @var boolean whether to use Mandrill async mode, defaults to false
     * @see https://mandrillapp.com/api/docs/messages.php.html#method=send
     */
    public $async = FALSE;

    /**
     * @var string ip pools to use, if any. No effect if not applicable
     * @see https://mandrillapp.com/api/docs/messages.php.html#method=send
     */
    public $ip_pool = NULL;

     /**
     * @var string template name, if using Mandrill template
     * @see https://mandrillapp.com/api/docs/messages.php.html#method=send
     */
    private $_templateName = NULL;

     /**
     * @var array template content to inject
     * @see https://mandrillapp.com/api/docs/messages.php.html#method=send
     */
    private $_templateContent = [];

    /**
     * @var Mandrill the Mandrill instance
     */
    private $_mandrill;

    /**
     * Checks that the API key has indeed been set.
     * 
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->_apikey) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" cannot be null.');
        }

        try {
            $this->_mandrill = new Mandrill($this->_apikey);
        } catch (\Exception $exc) {
            \Yii::error($exc->getMessage());
            throw new Exception('an error occurred with your mailer. Please check the application logs.', 500);
        }
    }

    /**
     * Sets the API key for Mandrill
     * 
     * @param string $apikey the Mandrill API key
     * @throws InvalidConfigException
     */
    public function setApikey($apikey)
    {
        if (!is_string($apikey)) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" should be a string, "' . gettype($apikey) . '" given.');
        }

        $apikey = trim($apikey);
        if (!strlen($apikey) > 0) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" length should be greater than 0.');
        }

        $this->_apikey = $apikey;
    }

    /**
     * Creates a new message instance and sets the mandrill template_name and template_content vars
     *
     *  \Yii::$app->mailer
     *       ->composeTemplate('mandrill-template',[['name'=>$mcEditableRegion, 'content'=>$content]])
     *       ->setTo('email@host.com')
     *       ->setGlobalMergeVars([$key=>$value,$key2=>$value2])
     *       ->setSendAt(strtotime("now +10 seconds")) //OR "YYYY-MM-DD HH:MM:SS"
     *       ->send();
     *
     * @param string template_name the Mandrill template name to be used
     * @param array template_content content to be injected
     *
     *
     * @return MessageInterface message instance.
     */
    public function composeTemplate($template_name = null, array $template_content = [])
    {
        if ($template_name === null) {
            throw new Exception('template_name must not be null when using composeTemplate', 500);
        }
        $message = $this->compose();
        $this->_templateName = $template_name;
        $this->_templateContent = $template_content;
        return $message;
    }

    /**
     * Sends the specified message.
     * 
     * @param Message $message the message to be sent
     * @return boolean whether the message is sent successfully
     */
    protected function sendMessage($message)
    {
        $address = $address = implode(', ', $message->getTo());
        \Yii::info('Sending email "' . $message->getSubject() . '" to "' . $address . '"', self::LOG_CATEGORY);

        //If a template is present, we will assume a template send.
        if ($this->_templateName)
        {
            try {
                return $this->wasMessageSentSuccesfully($this->_mandrill->messages->sendTemplate(
                        $this->_templateName,
                        $this->_templateContent,
                        $message->getMandrillMessageArray(),
                        $this->async,
                        $this->ip_pool,
                        $message->sendAt));
            } catch (Mandrill_Error $e) {
                \Yii::error('A mandrill error occurred: ' . get_class($e) . ' - ' . $e->getMessage(), self::LOG_CATEGORY);
                return false;
            }
        }
        else
        {
            try {
                return $this->wasMessageSentSuccesfully($this->_mandrill->messages->send($message->getMandrillMessageArray()));
            } catch (Mandrill_Error $e) {
                \Yii::error('A mandrill error occurred: ' . get_class($e) . ' - ' . $e->getMessage(), self::LOG_CATEGORY);
                return false;
            }
        }
    }

    /**
     * parse the mandrill response and returns false if any message was either invalid or rejected
     * 
     * @param array $mandrillResponse
     * @return boolean
     */
    private function wasMessageSentSuccesfully($mandrillResponse)
    {
        $return = true;
        foreach ($mandrillResponse as $recipient) {
            switch ($recipient['status']) {
                case self::STATUS_INVALID:
                    $return = false;
                    \Yii::warning('the email for "' . $recipient['email'] . '" has not been sent: status "' . $recipient['status'] . '"', self::LOG_CATEGORY);
                    break;
                case self::STATUS_QUEUED:
                    \Yii::info('the email for "' . $recipient['email'] . '" is now in a queue waiting to be sent.', self::LOG_CATEGORY);
                    break;
                case self::STATUS_REJECTED:
                    $return = false;
                    \Yii::warning('the email for "' . $recipient['email'] . '" has been rejected: reason "' . $recipient['reject_reason'] . '"', self::LOG_CATEGORY);
                    break;
                case self::STATUS_SCHEDULED:
                    \Yii::info('the email submission for "' . $recipient['email'] . '" has been scheduled.', self::LOG_CATEGORY);
                    break;
                case self::STATUS_SENT:
                    \Yii::info('the email for "' . $recipient['email'] . '" has been sent.', self::LOG_CATEGORY);
                    break;
            }
        }

        return $return;
    }

}
