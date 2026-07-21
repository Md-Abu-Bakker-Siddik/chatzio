<?php
/**
 * Chatzio - Chatbot Widget Host Element
 *
 * This is intentionally minimal. The entire widget UI is built by JavaScript
 * inside a closed Shadow DOM attached to this host element.
 * No theme CSS can reach inside. No widget CSS can leak out.
 */
if (!defined('ABSPATH')) exit;
?>
<div id="chatzio-host"></div>
