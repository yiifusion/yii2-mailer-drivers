<?php

namespace yiifusion\mail\transports\brevo;

use Throwable;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\httpclient\Client;
use yii\httpclient\Exception as HttpClientException;
use yii\httpclient\Request;
use yiifusion\exceptions\MailTransportException;
use yiifusion\mail\Message;
use yiifusion\mail\transports\BaseTransport;

use function array_keys;
use function base64_encode;
use function current;
use function get_class;
use function is_array;
use function is_numeric;
use function is_string;
use function key;
use function sprintf;

/**
 * BrevoTransport implements a mail transport based on the Brevo (formerly Sendinblue) API.
 *
 * To use BrevoTransport, you should configure it in the application configuration like the following:
 *
 * ```php
 * 'components' => [
 *     'mailer' => [
 *         'class' => 'yiifusion\mail\Mailer',
 *         'transportClass' => 'yiifusion\mail\transports\brevo\BrevoTransport',
 *         'transportConfig' => [
 *             'apiKey' => 'your-api-key-here',
 *         ],
 *     ],
 * ],
 * ```
 *
 * @see https://developers.brevo.com/docs/send-a-transactional-email
 *
 * @author YiiFusion Team
 */
class BrevoTransport extends BaseTransport
{
    /**
     * @var string the Brevo API key
     */
    public string $apiKey = '';

    /**
     * @var string the Brevo API endpoint
     */
    protected string $apiEndpoint = 'https://api.brevo.com/v3/smtp/email';

    /**
     * @var array<string, mixed> additional options for Brevo API
     *
     * @see https://developers.brevo.com/docs/send-a-transactional-email for available options
     */
    public array $brevoOptions = [];

    // SECTION: Initialization
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (empty($this->apiKey)) {
            throw new InvalidConfigException('BrevoTransport::apiKey must be set.');
        }

        parent::init();

        $this->log(
            'BrevoTransport initialized',
            [
                'endpoint'        => $this->apiEndpoint,
                'trackingEnabled' => $this->enableTracking,
            ]
        );
    }

    // SECTION: Actions
    /**
     * @inheritdoc
     */
    public function send(Message $message): bool
    {
        try {
            $this->log(
                'Preparing to send email via Brevo',
                [
                    'subject' => $message->getSubject(),
                    'to'      => array_keys($message->getTo()),
                    'from'    => array_keys($message->getFrom()),
                ]
            );

            $this->validateMessage($message);

            $payload = $this->buildPayload($message);
            $request = $this->createRequest($payload);

            $this->logRequest($request, 'Brevo API Request');

            $response = $request->send();

            $this->logResponse($response, 'Brevo API Response');

            /**
             * @param mixed $data
             *
             * @return array<string|null, int, string>
             */
            $normalizeData = static function (mixed $data, int $defaultStatusCode): array {
                if (!is_array($data)) {
                    $data = [];
                }

                $messageId = null;
                $code      = $defaultStatusCode;
                $message   = 'Unknown error';

                if (isset($data['messageId']) && (is_string($data['messageId']) || is_numeric($data['messageId']))) {
                    $messageId = (string)$data['messageId'];
                }

                if (isset($data['code']) && is_numeric($data['code'])) {
                    $code = (int)$data['code'];
                }

                if (isset($data['message']) && is_string($data['message'])) {
                    $message = (string)$data['message'];
                }

                return [$messageId, $code, $message];
            };

            $statusCode = $response->getStatusCode();

            if (is_numeric($statusCode)) {
                $statusCode = (int)$statusCode;
            } else {
                $statusCode = 500;
            }

            $content = $response->getData();

            [$messageId, $code, $responseMessage] = $normalizeData(
                $content,
                $statusCode
            );

            if ($statusCode === 201) {
                $this->logSendOperation(
                    $message,
                    true,
                    [
                        'statusCode' => $statusCode,
                        'messageId'  => $messageId,
                    ]
                );

                return true;
            }

            $errors = [];

            if (is_array($content)) {
                $errors[] = $responseMessage;

                $this->addError(
                    $code,
                    sprintf('Brevo API error (code: %s): %s', $code, $responseMessage),
                    $content
                );
            }

            if (empty($errors)) {
                $errors[] = 'Unknown error';

                $this->addError(
                    500,
                    sprintf('Brevo API error (code: %s): Unknown error', $response->getStatusCode()),
                    $content
                );
            }

            $this->logSendOperation(
                $message,
                false,
                [
                    'statusCode'   => $response->getStatusCode(),
                    'errorDetails' => $content,
                ]
            );

            return false;
        } catch (MailTransportException $e) {
            $this->addError($e->getCode(), $e->getMessage(), $e->getDetails());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => 'MailTransportException',
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        } catch (HttpClientException $e) {
            $this->addError(500, 'HTTP client error: ' . $e->getMessage());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => 'HttpClientException',
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        } catch (Throwable $e) {
            $this->addError(500, 'Unexpected error: ' . $e->getMessage());

            $this->logSendOperation(
                $message,
                false,
                [
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    // SECTION: Helpers

    // SUBSECTION: Payload
    /**
     * Builds the request payload for the Brevo API.
     *
     * @param Message $message the message to be sent
     *
     * @return array<mixed> the payload as an array
     */
    protected function buildPayload(Message $message): array
    {
        $payload = [
            'to'      => $this->formatEmailAddresses($message->getTo()),
            'subject' => $message->getSubject(),
            'sender'  => $this->formatSingleEmailAddress($message->getFrom()),
        ];

        if (!empty($message->getCc())) {
            $payload['cc'] = $this->formatEmailAddresses($message->getCc());
        }

        if (!empty($message->getBcc())) {
            $payload['bcc'] = $this->formatEmailAddresses($message->getBcc());
        }

        if (!empty($message->getReplyTo())) {
            $payload['replyTo'] = $this->formatSingleEmailAddress($message->getReplyTo());
        }

        // Set email content (HTML and/or text)
        if ($htmlBody = $message->getHtmlBody()) {
            $payload['htmlContent'] = $htmlBody;
        }

        if ($textBody = $message->getTextBody()) {
            $payload['textContent'] = $textBody;
        }

        // Add attachments if present
        if ($attachments = $message->getAttachments()) {
            $payload['attachment'] = $this->formatAttachments($attachments);
        }

        // Add headers if present
        if ($headers = $message->getHeaders()) {
            $payload['headers'] = $headers;
        }

        // Enable tracking if configured
        if ($this->enableTracking) {
            $payload['tracking'] = [
                'opens'  => true,
                'clicks' => true,
            ];
        }

        return ArrayHelper::merge(
            $payload,
            $this->getOptions(),
            $this->brevoOptions
        );
    }

    /**
     * Creates an HTTP request for the Brevo API.
     *
     * @param array<mixed> $payload the request payload
     *
     * @return Request the HTTP request
     */
    protected function createRequest(array $payload): Request
    {
        return $this->getHttpClient()
            ->createRequest()
            ->setMethod('POST')
            ->setUrl($this->apiEndpoint)
            ->setHeaders([
                'api-key'      => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])
            ->setFormat(Client::FORMAT_JSON)
            ->setData($payload);
    }

    // SUBSECTION: Email Formatters
    /**
     * Formats email addresses for the Brevo API.
     *
     * @param array<string, string> $addresses email addresses in the format [email => name]
     *
     * @return array<mixed> formatted email addresses
     */
    protected function formatEmailAddresses(array $addresses): array
    {
        $result = [];

        foreach ($addresses as $email => $name) {
            $result[] = $this->formatEmailAddress($email, $name);
        }

        return $result;
    }

    /**
     * Formats a single email address with name for Brevo API.
     *
     * @param string $email the email address
     * @param string $name the name associated with the email
     *
     * @return array<string, string> the formatted email address
     */
    protected function formatEmailAddress(string $email, string $name): array
    {
        $result = ['email' => $email];

        if (!empty($name)) {
            $result['name'] = $name;
        }

        return $result;
    }

    /**
     * Formats a single email address from an array of addresses.
     *
     * @param array<string, string> $addresses email addresses in the format [email => name]
     *
     * @return array<string, string> the first email address in the required format
     */
    protected function formatSingleEmailAddress(array $addresses): array
    {
        $email = key($addresses);
        $name  = current($addresses);

        return $this->formatEmailAddress((string)$email, (string)$name);
    }

    // SUBSECTION: Attachment Formatters
    /**
     * Formats attachments for the Brevo API.
     *
     * @param array<string, array<string, mixed>> $attachments the attachments from the message
     *
     * @return list<array{name: string, content: string, contentType?: string}> formatted attachments
     */
    protected function formatAttachments(array $attachments): array
    {
        $result = [];

        foreach ($attachments as $name => $attachment) {
            $content = $attachment['content'] ?? '';
            $file    = $attachment['fileName'] ?? '';

            if (!empty($content) && is_string($content) && is_string($file)) {
                $formattedAttachment = [
                    'name'    => $file,
                    'content' => base64_encode($content),
                ];

                if (isset($attachment['contentType']) && is_string($attachment['contentType'])) {
                    $formattedAttachment['contentType'] = $attachment['contentType'];
                }

                $result[] = $formattedAttachment;
            }
        }

        return $result;
    }
}
