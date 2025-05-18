<?php

namespace yiifusion\exceptions;

use Throwable;
use yii\base\Exception as YiiException;

/**
 * MailTransportException represents an exception occurred during mail transport operations.
 *
 * @author YiiFusion Team
 */
class MailTransportException extends YiiException
{
    /**
     * @var array<int, string>|null additional details about the exception
     */
    private ?array $details = null;

    /**
     * Constructor.
     *
     * @param string                  $message  the error message
     * @param int                     $code     the error code
     * @param Throwable|null          $previous the previous exception
     * @param array<int, string>|null $details  additional details about the exception
     */
    public function __construct($message = "", $code = 0, $previous = null, $details = null)
    {
        parent::__construct($message, $code, $previous);

        $this->details = $details;
    }

    /**
     * Returns additional details about the exception.
     *
     * @return array<int, string>|null additional details about the exception
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }
}
