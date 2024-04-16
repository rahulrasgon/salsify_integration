<?php

namespace Drupal\salsify_integration\Helpers;

use GuzzleHttp\Client;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Url;

/**
 * Class WebhookService.
 *
 * @see https://developers.salsify.com/v1.0/reference#webhook-signature-headers
 *
 */
class WebhookService {

  /**
   * Certificate Host URL.
   *
   * @var string
   */
  const EXPECTED_CERTIFICATE_HOST = 'webhooks-auth.salsify.com';

  /**
   * HTTPClient object.
   *
   * @var GuzzleHttp\Client
   */
  private $httpClient;

  /**
   * Logger object.
   *
   * @var Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private $logger;

  /**
   * The configuration object factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, Client $client, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $client;
    $this->logger = $logger_factory;
    $this->configFactory = $config_factory->getEditable('salsify_integration.settings');
  }

  /**
   * Verify the incoming webhook from Salsify.
   *
   * @param Request $request
   *
   * @return bool
  */
  public function verifyRequest(Request $request) {
    // Compare timestamps, don't allow anything older than 5 minutes
    $salsifyTimestamp = $request->headers->get('X-Salsify-Timestamp');
    if (time() - (5 * 60) > $salsifyTimestamp) {
      $this->logger->get('salsify_integration')->error('Request timestamp older than 5 minutes, aborting.');
      return false;
    }

    // Compare the Organization ID for the incoming webhook.
    $salsifyOrganizationId = $request->headers->get('X-Salsify-Organization-Id');
    $organization_id = $this->configFactory->get('organization_id');
    if ($salsifyOrganizationId != $organization_id) {
      $this->logger->get('salsify_integration')->error('Request Organization ID is different from the one configured in drupal, aborting.');
      return false;
    }

    $certificateUrl = $request->headers->get('X-Salsify-Cert-Url');
    $certificateUrlParts = parse_url($certificateUrl);
    // Ensure certificate url is  valid
    if ($certificateUrlParts['host'] != self::EXPECTED_CERTIFICATE_HOST
        || strtolower($certificateUrlParts['scheme']) !== 'https') {
      $this->logger->get('salsify_integration')->error("Certificate url invalid, aborting.");
      return false;
    }

    // Download the certificate
    $certificate = openssl_x509_read($this->httpClient->get($certificateUrl)->getBody());

    $signature = $request->headers->get('X-Salsify-Signature-V1');
    $requestId = $request->headers->get('X-Salsify-Request-Id');
    $payloadBody = $request->getContent();
    $webhook_url = Url::fromRoute('salsify_integration.listener', [], ['absolute' => 'true'])->toString();
    $signatureData = "{$salsifyTimestamp}.{$requestId}.{$salsifyOrganizationId}.{$webhook_url}.{$payloadBody}";

    $cert     = openssl_x509_read($certificate);
    $pubKey   = openssl_pkey_get_public($cert);

    $verified = openssl_verify($signatureData, base64_decode($signature), $pubKey, OPENSSL_ALGO_SHA256);
    \Drupal::logger('migration__post-import')->error($verified);
    if ($verified === 1) {
        return true;
    }

    if ($verified === 0) {
      $this->logger->get('salsify_integration')->error("The certificate was not verified.");
      return false;
    }

    $this->logger->get('salsify_integration')->error("Error validating certificate " . openssl_error_string());
    return false;
  }

}
