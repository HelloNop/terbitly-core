<?php

/**
 * @file plugins/generic/publisherApp/PublisherAppHandler.inc.php
 *
 * @class PublisherAppHandler
 * @ingroup plugins_generic_publisherApp
 *
 * @brief Answers the Publisher App about one submission of this journal: where it stands in OJS,
 * its title and authors. The app asks when someone clicks Sync status, Sync metadata or Get
 * submission from OJS. Only reads; signed with the journal's token like everything else.
 *
 * GET {journal}/publisherapp/submission/{id}
 */

import('classes.handler.Handler');

class PublisherAppHandler extends Handler {

	/** Requests older or newer than this are refused, as the app refuses the plugin's. */
	const MAX_SKEW_SECONDS = 300;

	function submission($args, $request) {
		header('Content-Type: application/json');
		$plugin = PluginRegistry::getPlugin('generic', 'publisherappplugin');
		$context = $request->getContext();
		$id = (int) ($args[0] ?? 0);

		if (!$plugin || !$context || !$id || !$this->signed($plugin, $context->getId(), $id)) {
			http_response_code(401);
			echo json_encode(array('message' => 'Not signed with this journal\'s token.'));
			return;
		}

		$submission = Services::get('submission')->get($id);
		if (!$submission || (int) $submission->getContextId() !== (int) $context->getId()) {
			echo json_encode(array('found' => false));
			return;
		}

		echo json_encode($plugin->submissionState($request, $context, $submission));
	}

	/** The same signature the plugin sends: HMAC-SHA256 of "timestamp\nGET\npath\n". */
	function signed($plugin, $contextId, $id) {
		$token = (string) $plugin->getSetting($contextId, 'token');
		if (strpos($token, '.') === false) return false;
		list($keyId, $secret) = explode('.', $token, 2);

		$key = isset($_SERVER['HTTP_X_PUBLISHER_KEY']) ? (string) $_SERVER['HTTP_X_PUBLISHER_KEY'] : '';
		$timestamp = isset($_SERVER['HTTP_X_PUBLISHER_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_PUBLISHER_TIMESTAMP'] : '';
		$signature = isset($_SERVER['HTTP_X_PUBLISHER_SIGNATURE']) ? (string) $_SERVER['HTTP_X_PUBLISHER_SIGNATURE'] : '';
		if ($key !== $keyId || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) return false;

		$expected = hash_hmac('sha256', $timestamp . "\nGET\n/publisherapp/submission/" . $id . "\n", $secret);
		return hash_equals($expected, $signature);
	}
}
