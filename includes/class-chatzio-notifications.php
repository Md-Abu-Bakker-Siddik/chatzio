<?php
/**
 * Chatzio - Email Notifications
 * Sends branded HTML emails on new leads and conversations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chatzio_Notifications {

    /**
     * Notify admin about a new lead
     */
    public static function notify_new_lead($lead) {
        $settings = get_option('chatzio_settings', []);

        if (empty($settings['notify_new_lead'])) return;

        $to = !empty($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        $site_name = get_bloginfo('name');
        $subject = "[{$site_name}] New Lead Captured - " . ($lead['email'] ?? 'Unknown');

        $body = self::build_email_template([
            'heading'  => 'New Lead Captured',
            'message'  => 'A new lead has been captured via your chatbot.',
            'details'  => [
                'Name'    => $lead['name'] ?: '(not provided)',
                'Email'   => $lead['email'],
                'Phone'   => $lead['phone'] ?: '(not provided)',
                'Source'  => ucfirst($lead['source'] ?? 'chat'),
                'Page'    => $lead['page_url'] ?: '(unknown)',
            ],
            'cta_text' => 'View in Dashboard',
            'cta_url'  => admin_url('admin.php?page=chatzio-conversations'),
        ]);

        self::send($to, $subject, $body);
    }

    /**
     * Notify admin about a new conversation (rate-limited: max 1 per session per hour).
     * Includes full conversation transcript, customer info, and topic filter.
     *
     * @param string      $session_id
     * @param string      $user_message  The triggering user message (first or latest)
     * @param string|null $topic         Resolved topic slug
     */
    public static function notify_new_conversation($session_id, $user_message, $topic = null) {
        $settings = get_option('chatzio_settings', []);

        if (empty($settings['notify_new_conversation'])) return;

        // Topic-based filter
        $notify_topics = isset($settings['notify_topics']) ? (array) $settings['notify_topics'] : [];
        if (!empty($notify_topics) && $topic !== null) {
            if (!in_array($topic, $notify_topics, true)) return;
        }

        // Rate limit: one notification per session per hour (prevents duplicate when topic fires twice)
        $transient_key = 'chatzio_conv_notif_' . md5($session_id);
        if (get_transient($transient_key)) return;
        set_transient($transient_key, 1, HOUR_IN_SECONDS);

        $to = !empty($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        $site_name = get_bloginfo('name');
        $topic_label = $topic ? ucfirst($topic) : 'General';
        $subject = "[{$site_name}] New Chat — {$topic_label}";

        $customer = self::get_customer_info($session_id);
        $transcript = self::get_conversation_transcript($session_id);

        $details = [];
        $details['Customer']  = $customer['name']  ?: '(unknown)';
        $details['Email']     = $customer['email'] ?: '(not captured)';
        $details['Topic']     = $topic_label;
        $details['Date']      = current_time('M j, Y g:i A');

        $body = self::build_email_template([
            'heading'    => 'New Chat Conversation',
            'message'    => 'A visitor has been chatting with your assistant.',
            'details'    => $details,
            'transcript' => $transcript,
            'cta_text'   => 'View Conversation',
            'cta_url'    => admin_url('admin.php?page=chatzio-conversations'),
        ]);

        self::send($to, $subject, $body);
    }

    /**
     * Send HTML email
     */
    private static function send($to, $subject, $html_body) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $html_body, $headers);

        if (!$sent) {
            Chatzio_Logger::log_warning('Email notification failed', [
                'to'      => $to,
                'subject' => $subject,
            ]);
        }
    }

    /**
     * Notify admin about a high-intent ("hot") lead
     * Triggered when lead score exceeds threshold
     */
    public static function notify_hot_lead($lead, $score) {
        $settings = get_option('chatzio_settings', []);

        if (empty($settings['notify_new_lead'])) return;

        // Only notify for high-intent leads (score >= 70)
        if ($score < 70) return;

        $to = !empty($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        $site_name = get_bloginfo('name');
        $subject = "[{$site_name}] Hot Lead Alert - " . ($lead['email'] ?? 'Unknown');

        $intent_label = $score >= 85 ? 'Very High' : 'High';

        $body = self::build_email_template([
            'heading'  => '🔥 Hot Lead Detected',
            'message'  => 'A high-intent visitor has been captured. They show strong buying signals and should be contacted soon.',
            'details'  => [
                'Email'         => $lead['email'],
                'Name'          => $lead['name'] ?: '(not provided)',
                'Phone'         => $lead['phone'] ?: '(not provided)',
                'Lead Score'    => "{$score}/100 ({$intent_label} Intent)",
                'Conversations' => $lead['conversation_count'] . ' chat(s)',
                'Source'        => ucfirst($lead['source'] ?? 'chat'),
                'Page'          => $lead['page_url'] ?: '(unknown)',
            ],
            'cta_text' => 'View Lead Now',
            'cta_url'  => admin_url('admin.php?page=chatzio-leads'),
        ]);

        self::send($to, $subject, $body);
    }

    /**
     * Notify admin instantly when chatbot fails to answer a query.
     * Rate-limited: max 1 notification per session per hour.
     */
    public static function notify_failed_query($session_id, $user_query, $bot_response, $topic = null) {
        $settings = get_option('chatzio_settings', []);

        if (empty($settings['notify_failed_query'])) return;

        // Topic-based filter
        $notify_topics = isset($settings['notify_topics']) ? (array) $settings['notify_topics'] : [];
        if (!empty($notify_topics) && $topic !== null) {
            if (!in_array($topic, $notify_topics, true)) return;
        }

        // Rate limit: max 1 per session per hour
        $transient_key = 'chatzio_failed_notif_' . md5($session_id);
        if (get_transient($transient_key)) return;
        set_transient($transient_key, 1, HOUR_IN_SECONDS);

        $to = !empty($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        $site_name = get_bloginfo('name');
        $topic_label = $topic ? ucfirst($topic) : 'General';
        $subject = "[{$site_name}] Unanswered Question — {$topic_label}";

        $customer   = self::get_customer_info($session_id);
        $transcript = self::get_conversation_transcript($session_id);

        $details = [];
        $details['Customer'] = $customer['name']  ?: '(unknown)';
        $details['Email']    = $customer['email'] ?: '(not captured)';
        $details['Topic']    = $topic_label;
        $details['Date']     = current_time('M j, Y g:i A');

        $body = self::build_email_template([
            'heading'    => 'Unanswered Question',
            'message'    => 'Your chatbot could not fully answer a customer\'s question. You may want to reach out to them directly or add the missing content.',
            'details'    => $details,
            'transcript' => $transcript,
            'cta_text'   => 'View Conversation',
            'cta_url'    => admin_url('admin.php?page=chatzio-conversations'),
        ]);

        self::send($to, $subject, $body);
    }

    /**
     * Send restock notification to a subscriber.
     *
     * @param string $to       Subscriber email.
     * @param array  $product  ['title' => string, 'url' => string, 'image' => string]
     */
    public static function send_restock_notification($to, $product) {
        $site_name = get_bloginfo('name');
        $subject = "[{$site_name}] {$product['title']} is back in stock!";

        $body = self::build_email_template([
            'heading'      => 'Great news! It\'s back in stock!',
            'message'      => 'The product you were waiting for is now available again.',
            'details'      => [
                'Product' => $product['title'],
                'Status'  => 'In Stock',
            ],
            'cta_text'    => 'Shop Now',
            'cta_url'     => $product['url'],
            'footer_note' => 'You received this because you signed up for restock notifications.',
        ]);

        self::send($to, $subject, $body);
    }

    /**
     * Send failed topics digest (scheduled via cron)
     */
    public static function send_failed_topics_digest() {
        $settings = get_option('chatzio_settings', []);

        if (empty($settings['notification_email'])) return;

        // Check if we've sent recently (daily)
        if (get_transient('chatzio_failed_digest_sent')) return;

        // Get failed topics from last 24 hours
        if (!class_exists('Chatzio_Failed_Topics')) return;

        $stats = Chatzio_Failed_Topics::get_stats(1);
        if ($stats['total'] < 3) return; // Only send if there are meaningful failures

        $top_queries = Chatzio_Failed_Topics::get_top_queries(1, 10);
        if (empty($top_queries)) return;

        $to = $settings['notification_email'];
        $site_name = get_bloginfo('name');
        $subject = "[{$site_name}] Daily Knowledge Gap Report";

        $topics_html = '<ul style="margin:8px 0 0;padding:0 0 0 18px;">';
        foreach ($top_queries as $q) {
            $topics_html .= '<li style="font-size:13px;color:#374151;line-height:1.7;margin-bottom:2px;">'
                . esc_html(mb_substr($q['query'], 0, 80))
                . ' <span style="color:#9ca3af;">(' . (int) $q['count'] . 'x)</span></li>';
        }
        $topics_html .= '</ul>';

        $top_cats = implode(', ', array_map(function($c) {
            return ucfirst($c['topic_category']);
        }, array_slice($stats['by_category'], 0, 3)));

        $body = self::build_email_template([
            'heading'      => 'Knowledge Gap Report',
            'message'      => "Your chatbot couldn't fully answer {$stats['total']} questions in the last 24 hours. Consider adding content for these topics:",
            'details'      => [
                'Failed Queries' => $stats['total'],
                'Top Categories' => $top_cats ?: '—',
            ],
            'content_html' => '<div style="margin:16px 0 0;padding:14px 16px;background:#fef9ec;border:1px solid #fde68a;border-radius:6px;">'
                . '<p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#92400e;letter-spacing:.5px;text-transform:uppercase;">Top Questions</p>'
                . $topics_html
                . '</div>',
            'cta_text'     => 'Improve Knowledge Base',
            'cta_url'      => admin_url('admin.php?page=chatzio-resources&tab=upload'),
        ]);

        self::send($to, $subject, $body);

        // Mark as sent
        set_transient('chatzio_failed_digest_sent', 1, DAY_IN_SECONDS);
    }

    /**
     * Send weekly performance summary
     */
    public static function send_weekly_summary() {
        $settings = get_option('chatzio_settings', []);

        if (empty($settings['notification_email'])) return;

        // Check if we've sent recently (weekly)
        if (get_transient('chatzio_weekly_summary_sent')) return;

        global $wpdb;
        $conv_table  = $wpdb->prefix . 'chatzio_conversations';
        $leads_table = $wpdb->prefix . 'chatzio_leads';

        $cutoff      = date('Y-m-d', strtotime('-7 days'));
        $prev_cutoff = date('Y-m-d', strtotime('-14 days'));

        $this_week_convs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $conv_table WHERE created_at >= %s", $cutoff
        ));
        $this_week_leads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $leads_table WHERE created_at >= %s", $cutoff
        ));
        $last_week_convs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $conv_table WHERE created_at >= %s AND created_at < %s", $prev_cutoff, $cutoff
        ));

        $trend       = $last_week_convs > 0 ? round((($this_week_convs - $last_week_convs) / $last_week_convs) * 100) : 0;
        $trend_text  = $trend > 0 ? "+{$trend}%" : "{$trend}%";
        $trend_emoji = $trend > 0 ? '📈' : ($trend < 0 ? '📉' : '➡️');

        if ($this_week_convs < 1) return;

        $to        = $settings['notification_email'];
        $site_name = get_bloginfo('name');
        $subject   = "[{$site_name}] Weekly Performance Report";

        $body = self::build_email_template([
            'heading' => 'Weekly Performance Summary',
            'message' => "Here's how your chatbot performed over the last 7 days:",
            'details' => [
                'Total Conversations' => number_format($this_week_convs),
                'New Leads Captured'  => number_format($this_week_leads),
                'Trend vs Last Week'  => "{$trend_emoji} {$trend_text}",
                'Period'              => date('M j', strtotime('-7 days')) . ' – ' . date('M j, Y'),
            ],
            'cta_text' => 'View Full Analytics',
            'cta_url'  => admin_url('admin.php?page=chatzio-analytics'),
        ]);

        self::send($to, $subject, $body);

        set_transient('chatzio_weekly_summary_sent', 1, WEEK_IN_SECONDS);
    }

    /**
     * Fetch customer name + email for a session.
     */
    private static function get_customer_info($session_id) {
        global $wpdb;
        $conv_table  = $wpdb->prefix . 'chatzio_conversations';
        $leads_table = $wpdb->prefix . 'chatzio_leads';

        $info = ['name' => '', 'email' => ''];

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT name, email FROM $leads_table WHERE session_id = %s ORDER BY id DESC LIMIT 1",
            $session_id
        ), ARRAY_A);
        if ($lead && !empty($lead['email'])) {
            $info['name']  = $lead['name'];
            $info['email'] = $lead['email'];
            return $info;
        }

        $user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(user_id) FROM $conv_table WHERE session_id = %s",
            $session_id
        ));
        if ($user_id > 0) {
            $wp_user = get_userdata($user_id);
            if ($wp_user) {
                $info['name']  = $wp_user->display_name;
                $info['email'] = $wp_user->user_email;
            }
        }

        return $info;
    }

    /**
     * Build conversation transcript as HTML.
     */
    private static function get_conversation_transcript($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'chatzio_conversations';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_message, bot_response, created_at FROM $table
             WHERE session_id = %s ORDER BY created_at ASC",
            $session_id
        ), ARRAY_A);

        if (empty($rows)) return '';

        $html  = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
        $html .= '<tr><td colspan="2" style="padding:0 0 8px;font-size:11px;font-weight:700;color:#6b7280;letter-spacing:.6px;text-transform:uppercase;border-bottom:1px solid #e5e7eb;">Conversation Transcript</td></tr>';

        foreach ($rows as $row) {
            $time = date('g:i A', strtotime($row['created_at']));
            $html .= '<tr><td style="padding:10px 0 4px;vertical-align:top;">'
                   . '<span style="display:inline-block;padding:2px 8px;background:#ede9fe;color:#5b21b6;border-radius:4px;font-size:11px;font-weight:700;margin-bottom:4px;">Customer &middot; ' . esc_html($time) . '</span><br>'
                   . '<span style="font-size:13px;color:#111827;line-height:1.6;">' . nl2br(esc_html(mb_substr($row['user_message'], 0, 500))) . '</span>'
                   . '</td></tr>';
            $html .= '<tr><td style="padding:4px 0 10px;vertical-align:top;border-bottom:1px solid #f3f4f6;">'
                   . '<span style="display:inline-block;padding:2px 8px;background:#dcfce7;color:#166534;border-radius:4px;font-size:11px;font-weight:700;margin-bottom:4px;">Bot</span><br>'
                   . '<span style="font-size:13px;color:#374151;line-height:1.6;">' . nl2br(esc_html(mb_substr(strip_tags($row['bot_response']), 0, 500))) . '</span>'
                   . '</td></tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Build a branded, cross-client HTML email template.
     *
     * @param array $data {
     *   heading      string  Required. Header text.
     *   message      string  Required. Body paragraph.
     *   details      array   Optional. label => value pairs shown in a table.
     *   transcript   string  Optional. Raw HTML transcript block.
     *   content_html string  Optional. Raw HTML inserted after details, before CTA.
     *   cta_text     string  Optional. Button label.
     *   cta_url      string  Optional. Button URL.
     *   footer_note  string  Optional. Footer sub-text (default: generic note).
     * }
     */
    private static function build_email_template($data) {
        // ── Branding from plugin settings ──────────────────────────────────────
        $settings  = get_option('chatzio_settings', []);
        $primary   = !empty($settings['primary_color'])   ? $settings['primary_color']   : '#4F46E5';
        $secondary = !empty($settings['secondary_color'])  ? $settings['secondary_color']  : '#6366F1';
        $logo_url  = !empty($settings['logo_url'])         ? esc_url($settings['logo_url']) : '';
        $site_name = esc_html(get_bloginfo('name'));
        $site_url  = esc_url(get_home_url());

        // ── Data ───────────────────────────────────────────────────────────────
        $heading      = isset($data['heading'])      ? $data['heading']                     : '';
        $message      = isset($data['message'])      ? $data['message']                     : '';
        $details      = isset($data['details'])      ? (array) $data['details']             : [];
        $transcript   = isset($data['transcript'])   ? $data['transcript']                  : '';
        $content_html = isset($data['content_html']) ? $data['content_html']                : '';
        $cta_text     = isset($data['cta_text'])     ? esc_html($data['cta_text'])          : '';
        $cta_url      = isset($data['cta_url'])      ? esc_url($data['cta_url'])            : '';
        $footer_note  = isset($data['footer_note'])  ? esc_html($data['footer_note'])       : 'You received this email because of your activity on our store.';

        // ── Details rows ───────────────────────────────────────────────────────
        $details_rows = '';
        foreach ($details as $label => $value) {
            if ($value === '' || $value === null) continue;
            $details_rows .=
                '<tr>' .
                  '<td bgcolor="#f9fafb" style="background-color:#f9fafb;padding:10px 16px;font-size:13px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;width:36%;white-space:nowrap;" valign="top">' . esc_html($label) . '</td>' .
                  '<td style="padding:10px 16px;font-size:13px;color:#6b7280;border-bottom:1px solid #e5e7eb;" valign="top">' . esc_html($value) . '</td>' .
                '</tr>';
        }

        // Transcript goes inside the details table as a full-width row
        $transcript_row = '';
        if (!empty($transcript)) {
            $transcript_row =
                '<tr>' .
                  '<td colspan="2" style="padding:16px;">' .
                    $transcript .
                  '</td>' .
                '</tr>';
        }

        $details_block = '';
        if ($details_rows || $transcript_row) {
            $details_block =
                '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;margin:20px 0 0;">' .
                  $details_rows .
                  $transcript_row .
                '</table>';
        }

        // ── content_html block (e.g. digest topics list) ───────────────────────
        $content_block = !empty($content_html) ? '<div style="margin-top:16px;">' . $content_html . '</div>' : '';

        // ── CTA — bulletproof button (VML for Outlook, <a> for everyone else) ─
        $cta_block = '';
        if ($cta_text && $cta_url) {
            $cta_block =
                '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">' .
                  '<tr><td align="center" style="padding:28px 0 8px;">' .
                    '<!--[if mso]>' .
                    '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" ' .
                      'href="' . $cta_url . '" ' .
                      'style="height:46px;v-text-anchor:middle;" arcsize="18%" stroke="f" fillcolor="' . $primary . '">' .
                      '<w:anchorlock/>' .
                      '<center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;">' . $cta_text . '</center>' .
                    '</v:roundrect>' .
                    '<![endif]-->' .
                    '<!--[if !mso]><!-->' .
                    '<a href="' . $cta_url . '" target="_blank" ' .
                      'style="display:inline-block;padding:13px 32px;background-color:' . $primary . ';color:#ffffff;text-decoration:none;' .
                             'border-radius:8px;font-size:14px;font-weight:700;font-family:Arial,Helvetica,sans-serif;' .
                             'letter-spacing:.2px;mso-hide:all;">' .
                      $cta_text .
                    '</a>' .
                    '<!--<![endif]-->' .
                  '</td></tr>' .
                '</table>';
        }

        // ── Logo block ─────────────────────────────────────────────────────────
        $logo_block = '';
        if ($logo_url) {
            $logo_block =
                '<tr>' .
                  '<td align="center" style="padding:28px 32px 0;">' .
                    '<a href="' . $site_url . '" target="_blank" style="display:inline-block;text-decoration:none;border:0;">' .
                      '<img src="' . $logo_url . '" alt="' . $site_name . '" width="56" height="56" border="0" ' .
                           'style="display:block;border:0;outline:none;text-decoration:none;border-radius:10px;width:56px;height:56px;object-fit:contain;">' .
                    '</a>' .
                  '</td>' .
                '</tr>';
        }

        $header_padding_top    = $logo_url ? '20px' : '32px';
        $header_padding_bottom = '28px';

        // ── Full template ──────────────────────────────────────────────────────
        return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
  <title>' . $site_name . '</title>
  <!--[if !mso]><!-->
  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
    @media only screen and (max-width: 620px) {
      .email-wrapper  { padding: 16px 8px !important; }
      .email-card     { border-radius: 0 !important; }
      .email-logo     { padding: 20px 20px 0 !important; }
      .email-header   { padding: ' . $header_padding_top . ' 20px ' . $header_padding_bottom . ' !important; }
      .email-body     { padding: 24px 20px 0 !important; }
      .email-footer   { padding: 20px !important; }
      h1.email-heading { font-size: 19px !important; }
    }
  </style>
  <!--<![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;word-spacing:normal;">
<!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%" bgcolor="#f1f5f9"><tr><td><![endif]-->
<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"
       class="email-wrapper" style="background-color:#f1f5f9;padding:40px 20px;">
  <tr>
    <td align="center">
      <!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" width="600"><tr><td><![endif]-->
      <table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"
             class="email-card" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;">

        ' . $logo_block . '

        <!-- HEADER -->
        <tr>
          <td class="email-header" bgcolor="' . $primary . '"
              style="background-color:' . $primary . ';padding:' . $header_padding_top . ' 32px ' . $header_padding_bottom . ';text-align:center;">
            <h1 class="email-heading"
                style="margin:0;color:#ffffff;font-size:21px;font-weight:700;font-family:Arial,Helvetica,sans-serif;line-height:1.3;letter-spacing:-.2px;">
              ' . esc_html($heading) . '
            </h1>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td class="email-body" style="padding:28px 32px 0;">
            <p style="margin:0;color:#374151;font-size:15px;line-height:1.7;font-family:Arial,Helvetica,sans-serif;">
              ' . esc_html($message) . '
            </p>
            ' . $details_block . '
            ' . $content_block . '
            ' . $cta_block . '
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td class="email-footer" style="padding:28px 32px;border-top:1px solid #e5e7eb;text-align:center;">
            <p style="margin:0 0 4px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#374151;">
              <a href="' . $site_url . '" target="_blank"
                 style="color:#374151;text-decoration:none;">' . $site_name . '</a>
            </p>
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9ca3af;line-height:1.5;">
              ' . $footer_note . '
            </p>
          </td>
        </tr>

      </table>
      <!--[if mso | IE]></td></tr></table><![endif]-->
    </td>
  </tr>
</table>
<!--[if mso | IE]></td></tr></table><![endif]-->
</body>
</html>';
    }
}
