<?php
/*
 * @copyright       (c) 2024. e-tailors IP B.V. All rights reserved
 * @author          Paul Maas <p.maas@e-tailors.com>
 *
 * @link            https://www.e-tailors.com
 */

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\AmazonSesBundle\Mailer\Transport\AmazonSesTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    private TranslatorInterface $translator;
    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private HttpClientInterface $client,
        TranslatorInterface $translator,
        ?LoggerInterface $logger = null,
    ) {
        $this->translator = $translator;
        $this->logger     = $logger;
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => ['processCallbackRequest', 0],
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        // Parse the payload to check if it's an SNS webhook
        try {
            $snsreq  = $event->getRequest();
            $payload = json_decode($snsreq->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            // Invalid JSON - not our webhook, let other handlers try
            return;
        }

        // Check if this is an SNS webhook by looking for SNS-specific structure
        // SNS webhooks have a "Type" field with specific values
        if (!array_key_exists('Type', $payload)) {
            // No "Type" field - not an SNS webhook
            return;
        }

        // Validate it's a known SNS Type
        $validSnsTypes = ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'];
        if (!in_array($payload['Type'], $validSnsTypes)) {
            // Has "Type" field but not a known SNS type - not our webhook
            return;
        }

        // This IS an SNS webhook! Process it regardless of transport configuration.
        // If SES is configured (either as default or via MultipleTransport), we should handle it.
        // If SES is NOT configured anywhere, we'll still process it (AWS is sending us webhooks
        // so it must have been configured at some point - better to handle bounces/complaints).
        
        $this->logger->debug('start processCallbackRequest - Amazon SNS Webhook');

        // Optional: Log if SES is not the default transport
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));
        if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME !== $dsn->getScheme()) {
            $this->logger->info('Processing SNS webhook (SES is not the default transport, may be secondary transport)');
        }

        $type = $payload['Type'];

        $proces_json_res = $this->processJsonPayload($payload, $type);

        if (true == $proces_json_res['hasError']) {
            $eventResponse = $this->createResponse($proces_json_res['message'], false);
        } else {
            $eventResponse = $this->createResponse($proces_json_res['message'], true);
        }

        $this->logger->debug('end processCallbackRequest - Amazon SNS Webhook');
        $event->setResponse($eventResponse);
    }

    /**
     * @return Response
     */
    private function createResponse($message, $success)
    {
        if (false == $success) {
            $statusCode = Response::HTTP_BAD_REQUEST;
        } else {
            $statusCode = Response::HTTP_OK;
        }

        return new Response(
            json_encode([
                'message' => $message,
                'success' => $success,
            ]),
            $statusCode,
            ['content-type' => 'application/json']
        );
    }

    /**
     * Process json request from Amazon SES.
     *
     * http://docs.aws.amazon.com/ses/latest/DeveloperGuide/best-practices-bounces-complaints.html
     *
     * @param array<string, mixed> $payload from Amazon SES
     */
    public function processJsonPayload(array $payload, $type): array
    {
        $typeFound = false;
        $hasError  = false;
        $message   = 'PROCESSED';
        switch ($type) {
            case 'SubscriptionConfirmation':
                $typeFound = true;

                $reason = null;

                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->client->request('GET', $payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                        break;
                    } else {
                        $reason = 'HTTP Code '.$response->getStatusCode().', '.$response->getContent();
                    }
                } catch (TransportExceptionInterface $e) {
                    $reason = $e->getMessage();
                }

                if (null !== $reason) {
                    $this->logger->error(
                        'Callback to SubscribeURL from Amazon SNS failed, reason: ',
                        ['reason' => $reason]
                    );

                    $hasError = true;
                    $message  = $this->translator->trans('mautic.amazonses.plugin.sns.callback.subscribe.error', [], 'validators');
                }

                break;

            case 'Notification':
                $typeFound = true;

                try {
                    $message = json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                    $this->processJsonPayload($message, $message['notificationType']);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload');
                    $hasError = true;
                    $message  = $this->translator->trans('mautic.amazonses.plugin.sns.callback.notification.json_invalid', [], 'validators');
                }

                break;

            case 'Delivery':
                // Nothing more to do here.
                $typeFound = true;

                break;

            case 'Complaint':
                $typeFound = true;

                $emailId = $this->getEmailHeader($payload);

                // Get bounced recipients in an array
                $complaintRecipients = $payload['complaint']['complainedRecipients'];
                foreach ($complaintRecipients as $complaintRecipient) {
                    // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
                    // abuse / auth-failure / fraud / not-spam / other / virus
                    $complianceCode = array_key_exists('complaintFeedbackType', $payload['complaint']) ? $payload['complaint']['complaintFeedbackType'] : 'unknown';
                    $this->transportCallback->addFailureByAddress($this->cleanupEmailAddress($complaintRecipient['emailAddress']), $complianceCode, DoNotContact::UNSUBSCRIBED, $emailId);
                    $this->logger->debug("Mark email '".$complaintRecipient['emailAddress']."' has complained, reason: ".$complianceCode);
                }
                break;

            case 'Bounce':
                $typeFound = true;
                if ('Permanent' == $payload['bounce']['bounceType']) {
                    $emailId           = $this->getEmailHeader($payload);
                    $bouncedRecipients = $payload['bounce']['bouncedRecipients'];
                    foreach ($bouncedRecipients as $bouncedRecipient) {
                        $bounceSubType    = $payload['bounce']['bounceSubType'];
                        $bounceDiagnostic = array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
                        $bounceCode       = 'AWS: '.$bounceSubType.': '.$bounceDiagnostic;

                        $this->transportCallback->addFailureByAddress($this->cleanupEmailAddress($bouncedRecipient['emailAddress']), $bounceCode, DoNotContact::BOUNCED, $emailId);
                        $this->logger->debug("Mark email '".$this->cleanupEmailAddress($bouncedRecipient['emailAddress'])."' as bounced, reason: ".$bounceCode);
                    }
                }
                break;
            default:
                $this->logger->warning(
                    'SES webhook payload, not processed due to unknown type.',
                    ['Type' => $payload['Type'], 'payload' => json_encode($payload)]
                );
                break;
        }

        if (!$typeFound) {
            $message = sprintf(
                $message = $this->translator->trans('mautic.amazonses.plugin.sns.callback.unkown_type', [], 'validators'),
                $type
            );
        }

        return [
            'hasError' => $hasError,
            'message'  => $message,
        ];
    }

    public function cleanupEmailAddress($email)
    {
        return preg_replace('/(.*)<(.*)>(.*)/s', '\2', $email);
    }

    public function getEmailHeader($payload)
    {
        if (!isset($payload['mail']['headers'])) {
            return null;
        }

        foreach ($payload['mail']['headers'] as $header) {
            if ('X-EMAIL-ID' === strtoupper($header['name'])) {
                return $header['value'];
            }
        }
    }
}
