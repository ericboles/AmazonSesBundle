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
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\EmailStatModel;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Model\DoNotContact as DoNotContactModel;
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
        private EmailStatModel $emailStatModel,
        private ContactFinder $contactFinder,
        private DoNotContactModel $dncModel,
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
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME !== $dsn->getScheme()) {
            return;
        }

        $this->logger->debug('start processCallbackRequest - Amazon SNS Webhook');

        try {
            $snsreq  = $event->getRequest();
            $rawContent = $snsreq->getContent();
            
            // Log the raw webhook payload
            $this->logger->debug('SNS Raw Payload: ' . $rawContent);
            
            $payload = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
            
            // Log the parsed payload
            $this->logger->debug('SNS Parsed Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            $this->logger->error('SNS: Invalid JSON Payload: ' . $e->getMessage());
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid', [], 'validators'),
                    false
                )
            );

            return;
        }

        if (0 !== json_last_error()) {
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid', [], 'validators'),
                    false
                )
            );

            return;
        }

        $type = '';
        if (array_key_exists('Type', $payload)) {
            $type = $payload['Type'];
        } elseif (array_key_exists('eventType', $payload)) {
            $type = $payload['eventType'];
        } elseif (array_key_exists('notificationType', $payload)) {
            $type = $payload['notificationType'];
        } else {
            $event->setResponse(
                $this->createResponse(
                    $this->translator->trans('mautic.amazonses.plugin.sns.callback.json.invalid_payload_type', [], 'validators'),
                    false
                )
            );

            return;
        }

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
                    $this->logger->debug('Processing Notification payload');
                    $message = json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                    $this->logger->debug('Parsed notification message: ' . json_encode($message, JSON_PRETTY_PRINT));
                    $this->processJsonPayload($message, $message['notificationType']);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload: ' . $e->getMessage());
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
                    
                    $cleanEmail = $this->cleanupEmailAddress($complaintRecipient['emailAddress']);
                    $this->logger->debug("Processing complaint for address={$cleanEmail}, emailId={$emailId}");
                    
                    // Handle complaint with proper email stat correlation  
                    $this->processComplaintWithEmailId($cleanEmail, $complianceCode, $emailId);
                    
                    $this->logger->debug("Mark email '{$cleanEmail}' as complained, reason: {$complianceCode}");
                }
                break;

            case 'Bounce':
                $typeFound = true;
                if ('Permanent' == $payload['bounce']['bounceType']) {
                    $emailId           = $this->getEmailHeader($payload);
                    $bouncedRecipients = $payload['bounce']['bouncedRecipients'];
                    
                    // Debug logging
                    $this->logger->debug("Processing bounce with emailId: " . ($emailId ? $emailId : 'NULL'));
                    
                    foreach ($bouncedRecipients as $bouncedRecipient) {
                        $bounceSubType    = $payload['bounce']['bounceSubType'];
                        $bounceDiagnostic = array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
                        $bounceCode       = 'AWS: '.$bounceSubType.': '.$bounceDiagnostic;

                        $cleanEmail = $this->cleanupEmailAddress($bouncedRecipient['emailAddress']);
                        $this->logger->debug("Processing bounce for address={$cleanEmail}, emailId={$emailId}");
                        
                        // Handle bounce with proper email stat correlation
                        $this->processBounceWithEmailId($cleanEmail, $bounceCode, $emailId);
                        
                        $this->logger->debug("Mark email '{$cleanEmail}' as bounced, reason: {$bounceCode}");
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
        $this->logger->debug('getEmailHeader called');
        
        if (!isset($payload['mail']['headers'])) {
            $this->logger->debug('No mail.headers found in payload');
            return null;
        }

        $this->logger->debug('Found ' . count($payload['mail']['headers']) . ' headers in payload');
        
        // Log all headers for debugging
        foreach ($payload['mail']['headers'] as $header) {
            $this->logger->debug('Header: ' . $header['name'] . ' = ' . $header['value']);
        }

        foreach ($payload['mail']['headers'] as $header) {
            if ('X-EMAIL-ID' === strtoupper($header['name'])) {
                $this->logger->debug('Found X-EMAIL-ID header with value: ' . $header['value']);
                return $header['value'];
            }
        }
        
        $this->logger->debug('X-EMAIL-ID header not found');
        return null;
    }

    /**
     * Process bounce with email ID correlation - enhanced version that works within plugin
     */
    private function processBounceWithEmailId(string $emailAddress, string $bounceCode, ?string $emailId): void
    {
        $this->logger->debug("processBounceWithEmailId called with address={$emailAddress}, emailId=" . ($emailId ?: 'NULL'));
        
        if (!$emailId) {
            // Fall back to standard method if no email ID
            $this->transportCallback->addFailureByAddress($emailAddress, $bounceCode, DoNotContact::BOUNCED);
            return;
        }

        // Try to find the specific email stat using email ID and address
        $stat = $this->emailStatModel->getRepository()->findOneBy([
            'email' => $emailId,
            'emailAddress' => $emailAddress
        ]);

        if ($stat) {
            $this->logger->debug("Found specific stat (ID: {$stat->getId()}) for email {$emailId} and address {$emailAddress}");
            
            // Update the stat directly (like TransportCallback::updateStatDetails does)
            $stat->setIsFailed(true);
            
            $openDetails = $stat->getOpenDetails();
            if (!isset($openDetails['bounces'])) {
                $openDetails['bounces'] = [];
            }
            $openDetails['bounces'][] = [
                'datetime' => (new \DateTime())->format('Y-m-d H:i:s'),
                'reason'   => $bounceCode,
            ];
            $stat->setOpenDetails($openDetails);
            $this->emailStatModel->saveEntity($stat);
            
            // Set DNC for the contact with proper email channel
            $contact = $stat->getLead();
            if ($contact) {
                $channel = ['email' => (int)$emailId];
                $this->logger->debug("Setting DNC for contact {$contact->getId()} with channel: " . json_encode($channel));
                $this->dncModel->addDncForContact($contact->getId(), $channel, DoNotContact::BOUNCED, $bounceCode);
            }
        } else {
            $this->logger->debug("No specific stat found for email {$emailId} and address {$emailAddress}, using fallback");
            
            // Fall back to finding contacts by address and setting DNC with email channel
            $result = $this->contactFinder->findByAddress($emailAddress);
            if ($contacts = $result->getContacts()) {
                foreach ($contacts as $contact) {
                    $channel = ['email' => (int)$emailId];
                    $this->logger->debug("Setting DNC for contact {$contact->getId()} with channel: " . json_encode($channel));
                    $this->dncModel->addDncForContact($contact->getId(), $channel, DoNotContact::BOUNCED, $bounceCode);
                }
            }
        }
    }

    /**
     * Process complaint with email ID correlation
     */
    private function processComplaintWithEmailId(string $emailAddress, string $complaintCode, ?string $emailId): void
    {
        $this->logger->debug("processComplaintWithEmailId called with address={$emailAddress}, emailId=" . ($emailId ?: 'NULL'));
        
        if (!$emailId) {
            // Fall back to standard method if no email ID
            $this->transportCallback->addFailureByAddress($emailAddress, $complaintCode, DoNotContact::UNSUBSCRIBED);
            return;
        }

        // Try to find the specific email stat using email ID and address
        $stat = $this->emailStatModel->getRepository()->findOneBy([
            'email' => $emailId,
            'emailAddress' => $emailAddress
        ]);

        if ($stat) {
            $this->logger->debug("Found specific stat (ID: {$stat->getId()}) for email {$emailId} and address {$emailAddress}");
            
            // Set DNC for the contact with proper email channel
            $contact = $stat->getLead();
            if ($contact) {
                $channel = ['email' => (int)$emailId];
                $this->logger->debug("Setting DNC (UNSUBSCRIBED) for contact {$contact->getId()} with channel: " . json_encode($channel));
                $this->dncModel->addDncForContact($contact->getId(), $channel, DoNotContact::UNSUBSCRIBED, $complaintCode);
            }
        } else {
            $this->logger->debug("No specific stat found for email {$emailId} and address {$emailAddress}, using fallback");
            
            // Fall back to finding contacts by address and setting DNC with email channel
            $result = $this->contactFinder->findByAddress($emailAddress);
            if ($contacts = $result->getContacts()) {
                foreach ($contacts as $contact) {
                    $channel = ['email' => (int)$emailId];
                    $this->logger->debug("Setting DNC (UNSUBSCRIBED) for contact {$contact->getId()} with channel: " . json_encode($channel));
                    $this->dncModel->addDncForContact($contact->getId(), $channel, DoNotContact::UNSUBSCRIBED, $complaintCode);
                }
            }
        }
    }
}
