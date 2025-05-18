<?php

namespace yiifusion\mail\transports\sendgrid;

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
use function is_string;
use function key;
use function sprintf;

/**
 * SendGridTransport implements a mail transport based on the SendGrid Mail API.
 *
 * To use SendGridTransport, you should configure it in the application configuration like the following:
 *
 * ```php
 * 'components' => [
 *     'mailer' => [
 *         'class' => 'yiifusion\mail\Mailer',
 *         'transportClass' => 'yiifusion\mail\transports\sendgrid\SendGridTransport',
 *         'transportConfig' => [
 *             'apiKey' => 'your-api-key-here',
 *         ],
 *     ],
 * ],
 * ```
 *
 * @see https://docs.sendgrid.com/api-reference/mail-send/mail-send
 *
 * @author YiiFusion Team
 */
class SendGridTransport extends BaseTransport
{
    /**
     * @var string the SendGrid API key
     */
    public string $apiKey = '';

    /**
     * @var string the SendGrid API endpoint
     */
    protected string $apiEndpoint = 'https://api.sendgrid.com/v3/mail/send';

    /**
     * @var string the SendGrid EU API endpoint
     */
    protected string $euApiEndpoint = 'https://api.eu.sendgrid.com/v3/mail/send';

    /**
     * @var array<string, mixed> additional options for SendGrid API
     *
     * @see https://docs.sendgrid.com/api-reference/mail-send/mail-send for available options
     */
    public array $sendGridOptions = [];

    /**
     * @var bool whether to use the SendGrid EU API
     */
    public bool $useEuApi = false;

    // SECTION: Initialization
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (empty($this->apiKey)) {
            throw new InvalidConfigException('SendGridTransport::apiKey must be set.');
        }

        parent::init();

        $this->log(
            'SendGridTransport initialized',
            [
                'endpoint'        => $this->useEuApi ? $this->euApiEndpoint : $this->apiEndpoint,
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
                'Preparing to send email via SendGrid',
                [
                    'subject' => $message->getSubject(),
                    'to'      => array_keys($message->getTo()),
                    'from'    => array_keys($message->getFrom()),
                ]
            );

            $this->validateMessage($message);

            $payload = $this->buildPayload($message);
            $request = $this->createRequest($payload);

            $this->logRequest($request, 'SendGrid API Request');

            $response = $request->send();

            $this->logResponse($response, 'SendGrid API Response');

            if ($response->getStatusCode() === 202) {
                $this->logSendOperation(
                    $message,
                    true,
                    [
                        'statusCode' => $response->getStatusCode(),
                    ]
                );

                return true;
            }

            $content = $response->getData();
            $errors  = [];

            if (is_array($content) && isset($content['errors']) && is_array($content['errors'])) {
                /** @var array<int, array{message: string, field: string}> $baseErrors */
                $baseErrors = $content['errors'];

                $errors = $this->normalizeErrors($content['errors']);
            }

            if (empty($errors)) {
                $errors[] = 'Unknown error';
            }

            foreach ($errors as $error) {
                $this->addError(
                    500,
                    sprintf('SendGrid API error (code: %s): %s', $response->getStatusCode(), $error),
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
     * Builds the request payload for the SendGrid API.
     *
     * @param Message $message the message to be sent
     *
     * @return array<mixed> the payload as an array
     */
    protected function buildPayload(Message $message): array
    {
        $payload = [
            'personalizations' => [
                [
                    'to' => $this->formatEmailAddresses($message->getTo()),
                ],
            ],
            'from'    => $this->formatSingleEmailAddress($message->getFrom()),
            'subject' => $message->getSubject(),
        ];

        if (!empty($message->getCc())) {
            $payload['personalizations'][0]['cc'] = $this->formatEmailAddresses($message->getCc());
        }

        if (!empty($message->getBcc())) {
            $payload['personalizations'][0]['bcc'] = $this->formatEmailAddresses($message->getBcc());
        }

        if (!empty($message->getReplyTo())) {
            $payload['reply_to'] = $this->formatSingleEmailAddress($message->getReplyTo());
        }

        $content = [];

        if ($textBody = $message->getTextBody()) {
            $content[] = [
                'type'  => 'text/plain',
                'value' => $textBody,
            ];
        }

        if ($htmlBody = $message->getHtmlBody()) {
            $content[] = [
                'type'  => 'text/html',
                'value' => $htmlBody,
            ];
        }

        $payload['content'] = $content;

        if ($attachments = $message->getAttachments()) {
            $payload['attachments'] = $this->formatAttachments($attachments);
        }

        if ($headers = $message->getHeaders()) {
            $payload['headers'] = $headers;
        }

        if ($this->enableTracking) {
            $payload['tracking_settings'] = [
                'click_tracking' => [
                    'enable' => true,
                ],
                'open_tracking' => [
                    'enable' => true,
                ],
            ];
        }

        return ArrayHelper::merge(
            $payload,
            $this->getOptions(),
            $this->sendGridOptions
        );
    }

    /**
     * Creates an HTTP request for the SendGrid API.
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
            ->setUrl($this->useEuApi ? $this->euApiEndpoint : $this->apiEndpoint)
            ->setHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
            ->setFormat(Client::FORMAT_JSON)
            ->setData($payload);
    }

    // SUBSECTION: Email Formatters
    /**
     * Formats email addresses for the SendGrid API.
     *
     * @param array<string, string> $addresses email addresses in the format [email => name]
     *
     * @return array<int, array<string, string>> formatted email addresses
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
     * Formats a single email address with name for SendGrid API.
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
     * Formats attachments for the SendGrid API.
     *
     * @param array<string, array<string, mixed>> $attachments the attachments from the message
     *
     * @return list<array{filename: string, content: string, disposition: string, type: string}> formatted attachments
     */
    protected function formatAttachments(array $attachments): array
    {
        $result = [];

        foreach ($attachments as $name => $attachment) {
            $content = $attachment['content'] ?? '';
            $file    = $attachment['fileName'] ?? '';

            if (!empty($content) && is_string($content) && is_string($file)) {
                $formattedAttachment = [
                    'filename'    => $file,
                    'content'     => base64_encode($content),
                    'disposition' => 'attachment',
                    'type'        => 'application/octet-stream',
                ];

                if (isset($attachment['contentType']) && is_string($attachment['contentType'])) {
                    $formattedAttachment['type'] = $attachment['contentType'];
                }

                $result[] = $formattedAttachment;
            }
        }

        return $result;
    }

    // SUBSECTION: Error Handling
    /**
     * Normalizes the SendGrid API errors.
     *
     * @param array<mixed, mixed> $baseErrors the errors from the SendGrid API
     *
     * @return list<string> normalized errors
     */
    protected function normalizeErrors(array $baseErrors): array
    {
        $errors = [];

        foreach ($baseErrors as $error) {
            $message = '';
            $field   = '';

            if (!is_array($error)) {
                continue;
            }

            if (!empty($error['message']) && is_string($error['message'])) {
                $message = $error['message'];
            }

            if (!empty($error['field']) && is_string($error['field'])) {
                $field = $error['field'];
            }

            if (!empty($message)) {
                if (!empty($field)) {
                    $message .= sprintf(' (field: %s)', $field);
                }

                $errors[] = $message;
            }
        }

        return $errors;
    }
}
