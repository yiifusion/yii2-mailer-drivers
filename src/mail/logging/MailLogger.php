<?php

namespace yiifusion\mail\logging;

use Yii;
use yii\base\BaseObject;
use yii\httpclient\Request;
use yii\httpclient\Response;
use yii\log\Logger;
use yii\web\HeaderCollection;
use yiifusion\mail\Message;

use function array_keys;
use function array_merge;
use function count;
use function date;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * MailLogger provides specialized logging functionality for mail operations.
 *
 * This class handles logging of mail delivery attempts, successes, failures, and other
 * mail-related operations while integrating with Yii's logging system. It can also
 * log raw HTTP requests and responses for API-based mail transports.
 *
 * @author YiiFusion Team
 */
class MailLogger extends BaseObject implements MailLoggerInterface
{
    /**
     * @var bool whether logging is enabled
     */
    public bool $enabled = true;

    /**
     * @var int the log level for standard operations
     */
    public int $logLevel = Logger::LEVEL_INFO;

    /**
     * @var int the log level for error operations
     */
    public int $errorLogLevel = Logger::LEVEL_WARNING;

    /**
     * @var string the log category for mail operations
     */
    public string $category = 'mail';

    /**
     * @var bool whether to include detailed message information in logs
     * Note: This may include sensitive data. Use with caution in production.
     */
    public bool $includeMessageDetails = false;

    /**
     * @var bool whether to log raw HTTP requests and responses
     */
    public bool $logRawHttp = false;

    /**
     * @var int maximum length of the raw request/response content to log
     * Set to 0 to log the entire content regardless of size
     */
    public int $maxRawContentLength = 4096;

    /**
     * @var array<int, string> headers that should be completely redacted in logs
     */
    public array $sensitiveHeaders = [
        'Authorization',
        'API-Key',
        'X-API-Key',
        'Password',
        'Secret',
        'Bearer',
        'Token',
        'Credentials',
    ];

    /**
     * @var array<int, string> request/response body fields that should be redacted
     */
    public array $sensitiveFields = [
        'password',
        'key',
        'secret',
        'token',
        'auth',
        'credential',
        'apiKey',
        'api_key',
        'access_token',
        'accessToken',
    ];

    /**
     * Logs a mail transport operation.
     *
     * @param string               $message the log message
     * @param array<string, mixed> $data    additional data to log
     * @param int|null             $level   the log level (if null, uses the default log level)
     */
    public function log(string $message, array $data = [], ?int $level = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $logLevel = $level ?? $this->logLevel;

        $logData = [
            'message'   => $message,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (!empty($data)) {
            $logData = array_merge($logData, $data);
        }

        $this->redactSensitiveData($logData);

        Yii::getLogger()->log((string)json_encode($logData), $logLevel, $this->category);
    }

    /**
     * Logs a message sending operation.
     *
     * @param Message              $message        the message being sent
     * @param bool                 $isSuccessful   whether the send operation was successful
     * @param string               $transportName  the name of the transport used
     * @param array<string, mixed> $additionalData additional data to log
     */
    public function logSendOperation(
        Message $message,
        bool $isSuccessful,
        string $transportName,
        array $additionalData = []
    ): void {
        $operation = $isSuccessful ? 'Email sent successfully' : 'Email sending failed';

        $logData = [
            'transport'  => $transportName,
            'subject'    => $message->getSubject(),
            'to'         => array_keys($message->getTo()),
            'from'       => array_keys($message->getFrom()),
            'successful' => $isSuccessful,
        ];

        if ($this->includeMessageDetails) {
            $logData['cc']              = array_keys($message->getCc());
            $logData['bcc']             = array_keys($message->getBcc());
            $logData['replyTo']         = array_keys($message->getReplyTo());
            $logData['hasTextBody']     = !empty($message->getTextBody());
            $logData['hasHtmlBody']     = !empty($message->getHtmlBody());
            $logData['attachmentCount'] = count($message->getAttachments());
            $logData['headers']         = $message->getHeaders();
        }

        if (!empty($additionalData)) {
            $logData = array_merge($logData, $additionalData);
        }

        $level = $isSuccessful ? $this->logLevel : $this->errorLogLevel;

        $this->log($operation, $logData, $level);
    }

    /**
     * Logs an HTTP request.
     *
     * @param Request $request the HTTP request to log
     * @param string  $context additional context information
     */
    public function logRequest(Request $request, string $context = ''): void
    {
        if (!$this->enabled || !$this->logRawHttp) {
            return;
        }

        $requestData = [
            'url'     => $request->getUrl(),
            'method'  => $request->getMethod(),
            'headers' => $this->redactHeaders($request->getHeaders()),
        ];

        $content = (string)$request->getContent();

        if (!empty($content)) {
            $requestData['content'] = $this->prepareContent($content);
        }

        $logData = [
            'context' => $context,
            'request' => $requestData,
        ];

        $this->log('HTTP Request', $logData);
    }

    /**
     * Logs an HTTP response.
     *
     * @param Response $response the HTTP response to log
     * @param string   $context  additional context information
     */
    public function logResponse(Response $response, string $context = ''): void
    {
        if (!$this->enabled || !$this->logRawHttp) {
            return;
        }

        $responseData = [
            'statusCode' => $response->getStatusCode(),
            'isOk'       => $response->getIsOk(),
            'headers'    => $this->redactHeaders($response->getHeaders()),
        ];

        $content = (string)$response->getContent();

        if (!empty($content)) {
            $responseData['content'] = $this->prepareContent($content);
        }

        $logData = [
            'context'  => $context,
            'response' => $responseData,
        ];

        $this->log('HTTP Response', $logData);
    }

    /**
     * Prepares content for logging, redacting sensitive information and truncating if needed.
     *
     * @param string $content the raw content
     *
     * @return string|array<mixed> the prepared content
     */
    protected function prepareContent(string $content): string|array
    {
        $parsed = json_decode($content, true);

        if (is_array($parsed)) {
            $redacted = $this->redactSensitiveFields($parsed);

            return $redacted;
        }

        if ($this->maxRawContentLength > 0 && strlen($content) > $this->maxRawContentLength) {
            $content = substr($content, 0, $this->maxRawContentLength);

            return sprintf('%s ... [truncated, total length: %d]', $content, strlen($content));
        }

        return $content;
    }

    /**
     * Redacts sensitive fields in an array recursively.
     *
     * @param array<mixed, mixed> $data the data to redact
     *
     * @return array<mixed> the redacted data
     */
    protected function redactSensitiveFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keyLower = strtolower($key);

                foreach ($this->sensitiveFields as $sensitiveField) {
                    if (str_contains($keyLower, strtolower($sensitiveField))) {
                        $data[$key] = '***REDACTED***';

                        break;
                    }
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->redactSensitiveFields($value);
            }
        }

        return $data;
    }

    /**
     * Redacts sensitive headers.
     *
     * @param HeaderCollection|array<mixed, mixed> $headers the headers to redact
     *
     * @return HeaderCollection|array<mixed, mixed> the redacted headers
     */
    protected function redactHeaders(HeaderCollection|array $headers): HeaderCollection|array
    {
        foreach ($headers as $name => $value) {
            if (is_string($name)) {
                $nameLower = strtolower($name);

                foreach ($this->sensitiveHeaders as $sensitiveHeader) {
                    if (strpos($nameLower, strtolower($sensitiveHeader)) !== false) {
                        $headers[$name] = '***REDACTED***';

                        break;
                    }
                }
            }
        }

        return $headers;
    }

    /**
     * Redacts sensitive information from log data.
     *
     * @param array<mixed, mixed> &$data the data to be redacted
     */
    protected function redactSensitiveData(array &$data): void
    {
        if (isset($data['headers']) && is_array($data['headers'])) {
            $data['headers'] = $this->redactHeaders($data['headers']);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactSensitiveFields($value);
            }
        }
    }
}
