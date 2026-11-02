<?php

declare(strict_types=1);

/*
 * Claude-Bundle: Contao als MCP-Server für Claude — lauffähig unter
 * Contao 4.13 und Contao 5.7.
 *
 * @license LGPL-3.0-or-later
 */

namespace Schachbulle\ContaoClaudeBundle\Mcp;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Übersetzt zwischen Symfony-Anfragen und PSR-7, wie es das SDK erwartet.
 *
 * Bewusst ohne symfony/psr-http-message-bridge: Das Paket fehlt in beiden
 * Testinstallationen, und die paar Zeilen hier ersparen eine weitere
 * Abhängigkeit. nyholm/psr7 liefern Contao 4.13 und 5.7 ohnehin mit.
 */
final class HttpBridge
{
	/**
	 * Wandelt eine Symfony-Anfrage in eine PSR-7-Anfrage.
	 *
	 * @param Request      $request Die Anfrage an /mcp
	 * @param Psr17Factory $factory Erzeugt Anfrage und Datenstrom
	 *
	 * @return ServerRequestInterface Dieselbe Anfrage mit allen Headern und dem Rumpf
	 */
	public static function toPsr(Request $request, Psr17Factory $factory): ServerRequestInterface
	{
		$psrRequest = $factory->createServerRequest($request->getMethod(), $request->getUri(), $request->server->all());

		foreach ($request->headers->all() as $name => $values) {
			$psrRequest = $psrRequest->withHeader((string) $name, $values);
		}

		return $psrRequest->withBody($factory->createStream((string) $request->getContent()));
	}

	/**
	 * Wandelt die PSR-7-Antwort des SDK in eine Symfony-Antwort.
	 *
	 * Eine Antwort als Ereignisstrom (text/event-stream) wird erst beim
	 * Senden gelesen, weil der Datenstrom des SDK seinen Inhalt dann erst
	 * erzeugt. Die lesenden Werkzeuge erzeugen nie einen solchen Strom; der
	 * Zweig ist für spätere Rückfragen an den Client da.
	 *
	 * @param ResponseInterface $psrResponse Die Antwort des SDK
	 *
	 * @return Response Die Antwort für den Symfony-Kernel
	 */
	public static function toSymfony(ResponseInterface $psrResponse): Response
	{
		$headers = $psrResponse->getHeaders();

		if (str_starts_with($psrResponse->getHeaderLine('Content-Type'), 'text/event-stream')) {
			$body = $psrResponse->getBody();

			return new StreamedResponse(static function () use ($body): void {
				while (!$body->eof()) {
					echo $body->read(8192);
					flush();
				}
			}, $psrResponse->getStatusCode(), $headers);
		}

		return new Response((string) $psrResponse->getBody(), $psrResponse->getStatusCode(), $headers);
	}
}
