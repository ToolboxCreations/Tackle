<?php

declare(strict_types=1);

/**
 * Small helpers for outbound HTTPS with proper certificate verification.
 *
 * Many PHP installs (notably on Windows) ship without a configured CA bundle,
 * which makes verified TLS fail out of the box. Rather than disable
 * verification - unacceptable for a tool that downloads and indexes code -
 * Tackle bundles Mozilla's CA list (certs/cacert.pem) and points curl at it
 * whenever the host hasn't configured its own.
 */
class Http
{
	/** The CA bundle curl should use, or null to rely on the system/ini config. */
	public static function caBundle(): ?string
	{
		// If the host has configured a bundle, trust it.
		if (ini_get('curl.cainfo') || ini_get('openssl.cafile')) {
			return null;
		}
		$bundled = __DIR__ . '/../certs/cacert.pem';
		return is_file($bundled) ? $bundled : null;
	}

	/**
	 * Apply verified-TLS options to a curl handle.
	 *
	 * @param \CurlHandle $ch
	 */
	public static function secure($ch): void
	{
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		$ca = self::caBundle();
		if ($ca !== null) {
			curl_setopt($ch, CURLOPT_CAINFO, $ca);
		}
	}
}
