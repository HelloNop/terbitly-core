<?php

/**
 * @file plugins/generic/terbitlyCore/TerbitlyCoreHandler.inc.php
 *
 * @class TerbitlyCoreHandler
 * @ingroup plugins_generic_terbitlyCore
 *
 * @brief Answers Terbitly about this journal: where one submission stands in OJS (Sync,
 * Get submission from OJS), the journal's user groups and the team's past work (PRD-TEAM-02 §12).
 * Only reads; signed with the journal's token like everything else.
 *
 * GET {journal}/terbitly/submission/{id}
 * GET {journal}/terbitly/groups
 * GET {journal}/terbitly/history?since=YYYY-MM-DD&page=N
 */

import('classes.handler.Handler');

class TerbitlyCoreHandler extends Handler {

	/** Requests older or newer than this are refused, as the app refuses the plugin's. */
	const MAX_SKEW_SECONDS = 300;

	function submission($args, $request) {
		$id = (int) ($args[0] ?? 0);
		list($plugin, $context) = $this->signedRequest($request, '/terbitly/submission/' . $id);
		if (!$plugin || !$id) return;

		$submission = Services::get('submission')->get($id);
		if (!$submission || (int) $submission->getContextId() !== (int) $context->getId()) {
			echo json_encode(array('found' => false));
			return;
		}

		echo json_encode($plugin->submissionState($request, $context, $submission));
	}

	function groups($args, $request) {
		list($plugin, $context) = $this->signedRequest($request, '/terbitly/groups');
		if (!$plugin) return;

		echo json_encode(array('groups' => $plugin->userGroups($context->getId())));
	}

	function history($args, $request) {
		$since = (string) $request->getUserVar('since');
		$page = max(1, (int) $request->getUserVar('page'));
		list($plugin, $context) = $this->signedRequest($request, '/terbitly/history?' . http_build_query(array('since' => $since, 'page' => $page)));
		if (!$plugin) return;

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
			http_response_code(422);
			echo json_encode(array('message' => 'Give since as YYYY-MM-DD.'));
			return;
		}

		echo json_encode($plugin->history($context->getId(), $since, $page));
	}

	/**
	 * The plugin and journal when the request is signed for $path, else answers 401 and gives nulls.
	 * The same signature the plugin sends: HMAC-SHA256 of "timestamp\nGET\npath\n".
	 */
	function signedRequest($request, $path) {
		header('Content-Type: application/json');
		$plugin = PluginRegistry::getPlugin('generic', 'terbitlycoreplugin');
		$context = $request->getContext();

		if (!$plugin || !$context || !$this->signed($plugin, $context->getId(), $path)) {
			http_response_code(401);
			echo json_encode(array('message' => 'Not signed with this journal\'s token.'));
			return array(null, null);
		}
		return array($plugin, $context);
	}

	function signed($plugin, $contextId, $path) {
		$token = (string) $plugin->getSetting($contextId, 'token');
		if (strpos($token, '.') === false) return false;
		list($keyId, $secret) = explode('.', $token, 2);

		$key = isset($_SERVER['HTTP_X_PUBLISHER_KEY']) ? (string) $_SERVER['HTTP_X_PUBLISHER_KEY'] : '';
		$timestamp = isset($_SERVER['HTTP_X_PUBLISHER_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_PUBLISHER_TIMESTAMP'] : '';
		$signature = isset($_SERVER['HTTP_X_PUBLISHER_SIGNATURE']) ? (string) $_SERVER['HTTP_X_PUBLISHER_SIGNATURE'] : '';
		if ($key !== $keyId || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) return false;

		$expected = hash_hmac('sha256', $timestamp . "\nGET\n" . $path . "\n", $secret);
		return hash_equals($expected, $signature);
	}
}
