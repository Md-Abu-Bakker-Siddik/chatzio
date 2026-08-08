<?php
if (!defined('ABSPATH')) exit;
?>

<div class="wrap chatzio-admin">
    <div class="chatzio-shell">
        <div class="chatzio-page-header">
            <div>
                <h1 class="chatzio-page-title">
                    <span class="dashicons dashicons-format-chat"></span>
                    Chatzio Settings
                </h1>
                <p class="chatzio-page-subtitle">Configure your assistant, appearance, and advanced sync preferences.</p>
            </div>
        </div>

        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="chatzio-toast chatzio-toast-success" id="chatzio-settings-toast">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="10" fill="#16A34A"/><path d="M6 10.5L8.5 13L14 7.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Settings saved successfully!</span>
                <button type="button" class="chatzio-toast-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <div class="chatzio-tabs chatzio-card">
        <nav class="nav-tab-wrapper">
            <a href="#general" class="nav-tab nav-tab-active">General</a>
            <a href="#behavior" class="nav-tab">Bot Behavior</a>
            <a href="#api" class="nav-tab">API Settings</a>
            <a href="#appearance" class="nav-tab">Appearance</a>
            <a href="#widget-layout" class="nav-tab">Tab Settings</a>
            <a href="#leads" class="nav-tab">Lead Capture</a>
            <a href="#notifications" class="nav-tab">Notifications</a>
            <a href="#advanced" class="nav-tab">Advanced</a>
        </nav>
        
        <form method="post" action="options.php" class="chatzio-form">
            <?php settings_fields('chatzio_settings_group'); ?>
            
            <!-- General Tab -->
            <div id="general" class="tab-content active">
                <h2>General Settings</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Chatbot</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[enabled]" value="1" <?php checked(isset($settings['enabled']) ? $settings['enabled'] : true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Enable or disable the chatbot on your website</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Bot Name</th>
                        <td>
                            <input type="text" name="chatzio_settings[bot_name]" value="<?php echo esc_attr(isset($settings['bot_name']) ? $settings['bot_name'] : 'Chatzio'); ?>" class="regular-text">
                            <p class="description">The name displayed in the chatbot header</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Welcome Message</th>
                        <td>
                            <textarea name="chatzio_settings[welcome_message]" rows="3" class="large-text"><?php echo esc_textarea(isset($settings['welcome_message']) ? $settings['welcome_message'] : 'Hi! How can I help you today?'); ?></textarea>
                            <p class="description">The first message users see when they open the chat</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Input Placeholder</th>
                        <td>
                            <input type="text" name="chatzio_settings[input_placeholder]" value="<?php echo esc_attr(isset($settings['input_placeholder']) ? $settings['input_placeholder'] : 'Type your message...'); ?>" class="regular-text">
                            <p class="description">Placeholder text in the message input field</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Quick Reply Buttons</th>
                        <td>
                            <textarea name="chatzio_settings[quick_replies]" rows="4" class="large-text" placeholder="View Products&#10;Shipping Info&#10;Contact Us"><?php echo esc_textarea(isset($settings['quick_replies']) ? $settings['quick_replies'] : ''); ?></textarea>
                            <p class="description">
                                Suggested replies shown as clickable buttons under the message input. <strong>One per line.</strong><br>
                                Leave empty to disable. Example: "View Products", "Shipping Info", "Contact Us"
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            Proactive Message
                            <span class="chatzio-tooltip">?<span class="tooltip-text">A small floating text bubble that appears near the chat button after a delay. Great for engaging visitors who haven't opened the chat yet.</span></span>
                        </th>
                        <td>
                            <input type="text" name="chatzio_settings[proactive_message]" value="<?php echo esc_attr(isset($settings['proactive_message']) ? $settings['proactive_message'] : ''); ?>" class="regular-text" placeholder="e.g., Hi! Need help finding something?">
                            <p class="description">Leave empty to disable. This bubble appears near the chat icon to encourage visitors to engage.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Proactive Delay (seconds)</th>
                        <td>
                            <input type="number" name="chatzio_settings[proactive_delay]" value="<?php echo esc_attr(isset($settings['proactive_delay']) ? $settings['proactive_delay'] : '5'); ?>" min="0" max="60" step="1" class="small-text">
                            <p class="description">How many seconds after page load before the proactive message appears (0 = disabled)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Show Product Cards in Chat</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[enable_product_cards]" value="1" <?php checked(isset($settings['enable_product_cards']) ? $settings['enable_product_cards'] : true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">When enabled, the AI will display rich product cards (image, price, link) when recommending products. Disable if you don't sell products through your site.</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Bot Behavior Tab -->
            <div id="behavior" class="tab-content">
                <h2>Bot Behavior & Personality</h2>
                
                <div class="chatzio-notice">
                    <p><strong>Simple prompt setup:</strong> Run a Content Sync to auto-generate a smart prompt based on your site. Add optional custom instructions below if needed. Core rules (anti-hallucination, product recommendations) are built-in.</p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Generate prompt from synced content</th>
                        <td>
                            <label class="chatzio-switch">
                                <?php
                                // Default to enabled (true) if not set
                                $generate_prompt_default = !isset($settings['generate_prompt_on_sync']) ? true : !empty($settings['generate_prompt_on_sync']);
                                ?>
                                <input type="checkbox" name="chatzio_settings[generate_prompt_on_sync]" value="1" <?php checked($generate_prompt_default); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">When enabled, each Content Sync uses OpenRouter to generate a role-specific prompt from your pages, posts, and products. The AI-generated prompt below is updated after every sync.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            AI-Generated Prompt
                            <span class="chatzio-tooltip">?<span class="tooltip-text">Auto-generated from your synced content. Defines the chatbot's role, what your site offers, and how to assist visitors. Updated each time you run Content Sync. You can edit it here.</span></span>
                        </th>
                        <td>
                            <textarea name="chatzio_settings[synced_content_prompt]" rows="10" class="large-text code" placeholder="Run Content Sync to generate a prompt based on your site..."><?php echo esc_textarea(isset($settings['synced_content_prompt']) ? $settings['synced_content_prompt'] : ''); ?></textarea>
                            <p class="description">
                                Filled automatically when you sync content (if enabled above). Edit or clear if needed. This helps the bot understand its role and your site.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            Custom Instructions (optional)
                            <span class="chatzio-tooltip">?<span class="tooltip-text">Optional. Add any extra rules, tone, or instructions for the chatbot. Leave empty if you don't need it.</span></span>
                        </th>
                        <td>
                            <textarea name="chatzio_settings[custom_prompt]" rows="6" class="large-text code" placeholder="Add optional instructions here (e.g., tone, specific rules)..."><?php echo esc_textarea(isset($settings['custom_prompt']) ? $settings['custom_prompt'] : ''); ?></textarea>
                            <p class="description">
                                Optional. Add anything else you want the chatbot to follow. Leave empty if the AI-generated prompt is enough.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Restricted Topics</th>
                        <td>
                            <textarea name="chatzio_settings[restricted_topics]" rows="4" class="large-text" placeholder="Enter topics or phrases the bot should avoid (one per line)..."><?php echo esc_textarea(isset($settings['restricted_topics']) ? $settings['restricted_topics'] : ''); ?></textarea>
                            <p class="description">List topics or keywords the bot should politely decline to discuss (one per line). The bot will redirect users to contact support instead.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Fallback Response</th>
                        <td>
                            <textarea name="chatzio_settings[fallback_response]" rows="3" class="large-text"><?php echo esc_textarea(isset($settings['fallback_response']) ? $settings['fallback_response'] : "I'm not sure I can help with that specific question. Would you like me to connect you with our support team, or is there something else I can assist you with?"); ?></textarea>
                            <p class="description">Response when the bot can't answer or the topic is restricted</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- API Settings Tab -->
            <div id="api" class="tab-content">
                <h2>OpenRouter API Configuration</h2>
                
                <div class="chatzio-notice">
                    <p><strong>Need an API key?</strong> Get yours at <a href="https://openrouter.ai/" target="_blank">OpenRouter.ai</a></p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key <span class="required">*</span></th>
                        <td>
                            <input type="password" name="chatzio_settings[openrouter_api_key]" value="<?php echo esc_attr(isset($settings['openrouter_api_key']) ? $settings['openrouter_api_key'] : ''); ?>" class="large-text">
                            <p class="description">Your OpenRouter API key. Also used for semantic search embeddings (openai/text-embedding-3-small).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Model</th>
                        <td>
                            <select name="chatzio_settings[openrouter_model]" class="regular-text" id="openrouter-model-select">
                                <?php
                                // Model IDs verified against OpenRouter API (Feb 2026)
                                $models = [
                                    // ⚡ Fastest & Cheapest (verified working)
                                    '⚡ Fastest & Cheapest' => [
                                        'openai/gpt-3.5-turbo' => 'GPT-3.5 Turbo ⭐ (fastest)',
                                        'openai/gpt-4o-mini' => 'GPT-4o Mini ⭐',
                                        'openai/gpt-4.1-nano' => 'GPT-4.1 Nano ⭐',
                                        'openai/gpt-4.1-mini' => 'GPT-4.1 Mini',
                                        'openai/gpt-5-nano' => 'GPT-5 Nano',
                                        'openai/gpt-5-mini' => 'GPT-5 Mini',
                                        'google/gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite',
                                        'google/gemini-2.0-flash-001' => 'Gemini 2.0 Flash',
                                        'google/gemini-2.5-flash' => 'Gemini 2.5 Flash',
                                        'anthropic/claude-3.5-haiku' => 'Claude 3.5 Haiku',
                                        'anthropic/claude-haiku-4.5' => 'Claude Haiku 4.5',
                                    ],
                                    // 🆓 Free Models
                                    '🆓 Free Models' => [
                                        'openrouter/free' => 'OpenRouter Free Router',
                                        'stepfun/step-3.5-flash:free' => 'Step 3.5 Flash (free)',
                                        'nvidia/nemotron-3-nano-30b-a3b:free' => 'Nemotron 3 Nano 30B (free)',
                                        'arcee-ai/trinity-mini:free' => 'Trinity Mini (free)',
                                    ],
                                    // 🏆 Flagship (Best Quality)
                                    '🏆 Flagship ( Slow Response Time)' => [
                                        'openai/gpt-5' => 'GPT-5',
                                        'openai/gpt-5.1' => 'GPT-5.1',
                                        'openai/gpt-5.2' => 'GPT-5.2 (Latest)',
                                        'openai/gpt-4o' => 'GPT-4o',
                                        'anthropic/claude-4.5-sonnet' => 'Claude 4.5 Sonnet',
                                        'anthropic/claude-opus-4.6' => 'Claude Opus 4.6',
                                        'google/gemini-2.5-pro' => 'Gemini 2.5 Pro',
                                        'google/gemini-3-pro-preview' => 'Gemini 3 Pro Preview',
                                    ],
                                ];
                                $current_model = isset($settings['openrouter_model']) ? $settings['openrouter_model'] : 'openai/gpt-3.5-turbo';
                                foreach ($models as $group => $group_models):
                                ?>
                                    <optgroup label="<?php echo esc_attr($group); ?>">
                                        <?php foreach ($group_models as $value => $label): ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($current_model, $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Choose the AI model to power your chatbot. Models marked (Free) have no API cost.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            Temperature
                            <span class="chatzio-tooltip">?<span class="tooltip-text">Controls randomness. 0 = deterministic, focused answers. 2 = highly creative, varied responses. 0.7 is a good default for customer support.</span></span>
                        </th>
                        <td>
                            <?php $temp_val = isset($settings['temperature']) ? $settings['temperature'] : '0.7'; ?>
                            <div class="chatzio-range-group">
                                <input type="range" class="chatzio-range-input" min="0" max="2" step="0.1" value="<?php echo esc_attr($temp_val); ?>">
                                <span class="chatzio-range-value"><?php echo esc_html($temp_val); ?></span>
                                <input type="hidden" name="chatzio_settings[temperature]" value="<?php echo esc_attr($temp_val); ?>">
                            </div>
                            <p class="description">Creativity level (0.0 - 2.0). Higher = more creative, Lower = more focused</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            Max Tokens
                            <span class="chatzio-tooltip">?<span class="tooltip-text">Maximum number of tokens (words/pieces) in the AI response. 2000 is good for most use cases. Increase for longer, detailed answers.</span></span>
                        </th>
                        <td>
                            <?php $tokens_val = isset($settings['max_tokens']) ? $settings['max_tokens'] : '2000'; ?>
                            <div class="chatzio-range-group">
                                <input type="range" class="chatzio-range-input" min="100" max="4000" step="100" value="<?php echo esc_attr($tokens_val); ?>">
                                <span class="chatzio-range-value"><?php echo esc_html($tokens_val); ?></span>
                                <input type="hidden" name="chatzio_settings[max_tokens]" value="<?php echo esc_attr($tokens_val); ?>">
                            </div>
                            <p class="description">Maximum response length (100 - 4000)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Test Connection</th>
                        <td>
                            <button type="button" id="test-api-btn" class="button button-secondary">
                                <span class="dashicons dashicons-yes-alt"></span> Test API Connection
                            </button>
                            <div id="test-api-result"></div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Appearance Tab -->
            <div id="appearance" class="tab-content">
                <h2>Customize Appearance</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">Primary Color</th>
                        <td>
                            <div class="chatzio-color-field">
                                <input type="text" name="chatzio_settings[primary_color]" value="<?php echo esc_attr(isset($settings['primary_color']) ? $settings['primary_color'] : '#4F46E5'); ?>" data-coloris class="chatzio-color-input">
                            </div>
                            <p class="description">Main color for chatbot elements (gradient start, buttons, links)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Secondary Color</th>
                        <td>
                            <div class="chatzio-color-field">
                                <input type="text" name="chatzio_settings[secondary_color]" value="<?php echo esc_attr(isset($settings['secondary_color']) ? $settings['secondary_color'] : '#6366F1'); ?>" data-coloris class="chatzio-color-input">
                            </div>
                            <p class="description">Gradient end color and hover accent</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Link Color</th>
                        <td>
                            <div class="chatzio-color-field">
                                <input type="text" name="chatzio_settings[link_color]" value="<?php echo esc_attr(isset($settings['link_color']) ? $settings['link_color'] : '#2563EB'); ?>" data-coloris class="chatzio-color-input">
                            </div>
                            <p class="description">Color for clickable links in bot responses</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Bot Logo</th>
                        <td>
                            <input type="hidden" name="chatzio_settings[logo_url]" id="logo-url" value="<?php echo esc_url(isset($settings['logo_url']) ? $settings['logo_url'] : ''); ?>">
                            <div class="logo-upload-box" id="logo-upload-box">
                                <?php if (!empty($settings['logo_url'])): ?>
                                    <img src="<?php echo esc_url($settings['logo_url']); ?>" alt="Logo Preview" class="logo-preview-img">
                                    <div class="logo-upload-overlay">
                                        <span class="dashicons dashicons-update"></span>
                                        <span>Change Logo</span>
                                    </div>
                                <?php else: ?>
                                    <div class="logo-upload-placeholder">
                                        <span class="dashicons dashicons-upload"></span>
                                        <span>Upload Logo</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <p class="description">Click the box above to upload a logo (recommended: 64x64px square)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Chat Bubble Size</th>
                        <td>
                            <select name="chatzio_settings[bubble_size]">
                                <?php
                                $bubble_sizes = [
                                    '50' => 'Small (50px)',
                                    '56' => 'Medium (56px)',
                                    '60' => 'Default (60px)',
                                    '64' => 'Large (64px)',
                                    '72' => 'Extra Large (72px)',
                                ];
                                $current_bubble = isset($settings['bubble_size']) ? $settings['bubble_size'] : '60';
                                foreach ($bubble_sizes as $value => $label):
                                ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_bubble, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Size of the chat toggle button</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Widget Position</th>
                        <td>
                            <select name="chatzio_settings[widget_position]" id="chatzio_widget_position">
                                <option value="bottom-right" <?php selected(isset($settings['widget_position']) ? $settings['widget_position'] : 'bottom-right', 'bottom-right'); ?>>Bottom Right</option>
                                <option value="bottom-left" <?php selected(isset($settings['widget_position']) ? $settings['widget_position'] : 'bottom-right', 'bottom-left'); ?>>Bottom Left</option>
                                <option value="custom" <?php selected(isset($settings['widget_position']) ? $settings['widget_position'] : '', 'custom'); ?>>Custom (pixels)</option>
                            </select>
                            <p class="description">Choose which corner of the screen the chat widget appears in.</p>
                            <div id="chatzio_custom_position_wrap" class="chatzio-custom-position-wrap" style="display: none; margin-top: 14px; padding: 14px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 6px;">
                                <p style="margin-top: 0;"><strong>Desktop</strong></p>
                                <p>
                                    <label>Bottom (px)</label>
                                    <input type="number" name="chatzio_settings[position_custom_desktop_bottom]" id="chatzio_position_custom_desktop_bottom" value="<?php echo esc_attr(isset($settings['position_custom_desktop_bottom']) ? $settings['position_custom_desktop_bottom'] : '24'); ?>" min="0" max="500" step="1" style="width: 80px; margin-right: 12px;">
                                    <label>Right (px)</label>
                                    <input type="number" name="chatzio_settings[position_custom_desktop_right]" id="chatzio_position_custom_desktop_right" value="<?php echo esc_attr(isset($settings['position_custom_desktop_right']) ? $settings['position_custom_desktop_right'] : '24'); ?>" min="0" max="500" step="1" style="width: 80px;">
                                </p>
                                <p><strong>Mobile</strong></p>
                                <p>
                                    <label>Bottom (px)</label>
                                    <input type="number" name="chatzio_settings[position_custom_mobile_bottom]" id="chatzio_position_custom_mobile_bottom" value="<?php echo esc_attr(isset($settings['position_custom_mobile_bottom']) ? $settings['position_custom_mobile_bottom'] : '20'); ?>" min="0" max="500" step="1" style="width: 80px; margin-right: 12px;">
                                    <label>Right (px)</label>
                                    <input type="number" name="chatzio_settings[position_custom_mobile_right]" id="chatzio_position_custom_mobile_right" value="<?php echo esc_attr(isset($settings['position_custom_mobile_right']) ? $settings['position_custom_mobile_right'] : '20'); ?>" min="0" max="500" step="1" style="width: 80px;">
                                </p>
                                <p class="description" style="margin-bottom: 0;">Distance from bottom and right edge of the viewport. Mobile applies below 480px width.</p>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Font Family</th>
                        <td>
                            <select name="chatzio_settings[font_family]">
                                <?php
                                $fonts = [
                                    'system' => 'System Default',
                                    'inter' => 'Inter',
                                    'roboto' => 'Roboto',
                                    'opensans' => 'Open Sans',
                                    'lato' => 'Lato',
                                    'poppins' => 'Poppins',
                                ];
                                $current_font = isset($settings['font_family']) ? $settings['font_family'] : 'system';
                                foreach ($fonts as $value => $label):
                                ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_font, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Font used in the chat interface</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Font Size</th>
                        <td>
                            <select name="chatzio_settings[font_size]">
                                <?php
                                $font_sizes = [
                                    '12' => 'Small (12px)',
                                    '13' => 'Medium Small (13px)',
                                    '14' => 'Default (14px)',
                                    '15' => 'Medium Large (15px)',
                                    '16' => 'Large (16px)',
                                ];
                                $current_size = isset($settings['font_size']) ? $settings['font_size'] : '14';
                                foreach ($font_sizes as $value => $label):
                                ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_size, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Base font size for chat messages</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- tab settings Tab -->
            <div id="widget-layout" class="tab-content">
                <div class="widget-layout-container">
                    <!-- Side Navigation -->
                    <nav class="widget-layout-nav">
                        <span class="wl-nav-group">Tabs</span>
                        <a href="#wl-mode" class="wl-nav-item active" data-section="wl-mode">
                            <span class="dashicons dashicons-admin-appearance"></span>
                            <span class="wl-nav-label">Tabs & layout</span>
                        </a>
                        <span class="wl-nav-group">Tab content</span>
                        <a href="#wl-home" class="wl-nav-item" data-section="wl-home">
                            <span class="dashicons dashicons-admin-home"></span>
                            <span class="wl-nav-label">Home tab</span>
                        </a>
                        <a href="#wl-faq" class="wl-nav-item" data-section="wl-faq">
                            <span class="dashicons dashicons-editor-help"></span>
                            <span class="wl-nav-label">FAQ tab</span>
                        </a>
                        <a href="#wl-products" class="wl-nav-item" data-section="wl-products">
                            <span class="dashicons dashicons-cart"></span>
                            <span class="wl-nav-label">Products tab</span>
                        </a>
                        <a href="#wl-news" class="wl-nav-item" data-section="wl-news">
                            <span class="dashicons dashicons-megaphone"></span>
                            <span class="wl-nav-label">News &amp; Updates</span>
                        </a>
                    </nav>

                    <!-- Content Sections -->
                    <div class="widget-layout-content">
                        <?php
                        // Product list for Home featured products (and Products section); computed once for both
                        $wl_all_products = [];
                        $wl_products_featured = isset($settings['products_featured']) && is_array($settings['products_featured']) ? array_map('intval', $settings['products_featured']) : [];
                        if (class_exists('WooCommerce')) {
                            $wl_products_query = new WP_Query([
                                'post_type' => 'product',
                                'posts_per_page' => 500,
                                'post_status' => 'publish',
                                'orderby' => 'title',
                                'order' => 'ASC',
                            ]);
                            if ($wl_products_query->have_posts()) {
                                while ($wl_products_query->have_posts()) {
                                    $wl_products_query->the_post();
                                    $p = wc_get_product(get_the_ID());
                                    if ($p) {
                                        $wl_all_products[] = [
                                            'id' => get_the_ID(),
                                            'title' => get_the_title(),
                                            'sku' => $p->get_sku(),
                                        ];
                                    }
                                }
                                wp_reset_postdata();
                            }
                        }

                        // News items (with stable ids) and featured ids for Home — computed once for News section and Home featured block
                        $wl_news_items = isset($settings['news_items']) ? $settings['news_items'] : [];
                        if (!is_array($wl_news_items)) {
                            $wl_news_items = json_decode($wl_news_items, true);
                            if (!is_array($wl_news_items)) $wl_news_items = [];
                        }
                        foreach ($wl_news_items as $idx => &$wl_news_item) {
                            if (!isset($wl_news_item['id']) || (string) $wl_news_item['id'] === '') {
                                $wl_news_item['id'] = 'n' . uniqid();
                            }
                        }
                        unset($wl_news_item);
                        $wl_news_featured = isset($settings['news_featured']) && is_array($settings['news_featured']) ? $settings['news_featured'] : [];
                        if (empty($wl_news_featured) && !empty($wl_news_items)) {
                            foreach ($wl_news_items as $it) {
                                if (!empty($it['featured'])) {
                                    $wl_news_featured[] = isset($it['id']) ? $it['id'] : '';
                                }
                            }
                            $wl_news_featured = array_values(array_filter($wl_news_featured));
                        }
                        ?>
                        <?php
                        $tab_keys = ['home' => 'Home', 'chat' => 'Chat', 'faq' => 'FAQ', 'products' => 'Products', 'history' => 'History', 'news' => 'News'];
                        $tab_icons_default = ['home' => 'home', 'chat' => 'chat', 'faq' => 'faq', 'products' => 'products', 'history' => 'history', 'news' => 'news'];
                        $tab_labels = isset($settings['tab_labels']) && is_array($settings['tab_labels']) ? $settings['tab_labels'] : [];
                        $tab_icons = isset($settings['tab_icons']) && is_array($settings['tab_icons']) ? $settings['tab_icons'] : [];
                        $default_tab = isset($settings['default_tab']) ? $settings['default_tab'] : 'home';
                        $valid_default_tabs = ['home', 'chat', 'faq', 'products', 'history', 'news'];
                        if (!in_array($default_tab, $valid_default_tabs, true)) $default_tab = 'home';
                        ?>
                        <!-- Tabs & layout Section -->
                        <section id="wl-mode" class="wl-section active">
                            <div class="wl-section-header">
                                <h3>Tabs & layout</h3>
                                <p>Configure which tabs the widget shows, their names and icons, and which opens first.</p>
                            </div>
                            
                            <div class="wl-field-group">
                                <label class="wl-field-label">
                                    Widget Mode
                                    <span class="chatzio-tooltip">?<span class="tooltip-text">Tabbed mode shows a modern tab bar with Home, Chat, FAQ, etc. Simple mode is a classic single-panel chatbot.</span></span>
                                </label>
                                <select name="chatzio_settings[widget_mode]" id="widget-mode-select" class="wl-select">
                                    <?php
                                    $widget_modes = [
                                        'tabbed' => 'Multi-tab Interface',
                                        'simple' => 'Classic Single Tab',
                                    ];
                                    $current_mode = isset($settings['widget_mode']) ? $settings['widget_mode'] : 'tabbed';
                                    foreach ($widget_modes as $value => $label):
                                    ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($current_mode, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wl-field-help">Choose between a multi-tab experience or a classic single-panel chat</p>
                            </div>

                            <div id="tabbed-options" class="<?php echo $current_mode === 'simple' ? 'hidden' : ''; ?>">
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Enable Tabs</label>
                                    <p class="wl-field-help" style="margin-bottom: 20px !important;">Choose which tabs to display (max 5 including Chat). FAQ and Products are separate tabs.</p>
                                    <div class="wl-tabs-grid" id="wl-tabs-grid">
                                        <label class="wl-tab-card">
                                            <input type="checkbox" name="chatzio_settings[widget_tab_home]" value="1" class="wl-tab-opt" <?php checked(isset($settings['widget_tab_home']) ? $settings['widget_tab_home'] : true); ?>>
                                            <span class="wl-tab-icon">🏠</span>
                                            <span class="wl-tab-name">Home</span>
                                            <span class="wl-tab-desc">Welcome screen</span>
                                        </label>
                                        <label class="wl-tab-card disabled">
                                            <input type="checkbox" checked disabled>
                                            <span class="wl-tab-icon">💬</span>
                                            <span class="wl-tab-name">Chat</span>
                                            <span class="wl-tab-desc">Always on</span>
                                        </label>
                                        <label class="wl-tab-card">
                                            <input type="checkbox" name="chatzio_settings[widget_tab_faq]" value="1" class="wl-tab-opt" <?php checked(isset($settings['widget_tab_faq']) ? $settings['widget_tab_faq'] : false); ?>>
                                            <span class="wl-tab-icon">❓</span>
                                            <span class="wl-tab-name">FAQ</span>
                                            <span class="wl-tab-desc">Q&A pairs</span>
                                        </label>
                                        <label class="wl-tab-card">
                                            <input type="checkbox" name="chatzio_settings[widget_tab_products]" value="1" class="wl-tab-opt" <?php checked(isset($settings['widget_tab_products']) ? $settings['widget_tab_products'] : false); ?>>
                                            <span class="wl-tab-icon">🛒</span>
                                            <span class="wl-tab-name">Products</span>
                                            <span class="wl-tab-desc">Browse products</span>
                                        </label>
                                        <label class="wl-tab-card">
                                            <input type="checkbox" name="chatzio_settings[widget_tab_history]" value="1" class="wl-tab-opt" <?php checked(isset($settings['widget_tab_history']) ? $settings['widget_tab_history'] : false); ?>>
                                            <span class="wl-tab-icon">📋</span>
                                            <span class="wl-tab-name">History</span>
                                            <span class="wl-tab-desc">Past chats</span>
                                        </label>
                                        <label class="wl-tab-card">
                                            <input type="checkbox" name="chatzio_settings[widget_tab_news]" value="1" class="wl-tab-opt" <?php checked(isset($settings['widget_tab_news']) ? $settings['widget_tab_news'] : false); ?>>
                                            <span class="wl-tab-icon">📰</span>
                                            <span class="wl-tab-name">News</span>
                                            <span class="wl-tab-desc">Updates & offers</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="wl-field-group">
                                    <label class="wl-field-label">Tab Style</label>
                                    <select name="chatzio_settings[widget_tab_style]" class="wl-select">
                                        <?php
                                        $tab_styles = [
                                            'icons_labels' => 'Icons + Labels',
                                            'icons' => 'Icons Only (Compact)',
                                        ];
                                        $current_style = isset($settings['widget_tab_style']) ? $settings['widget_tab_style'] : 'icons_labels';
                                        foreach ($tab_styles as $value => $label):
                                        ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($current_style, $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="wl-field-help">Choose how tabs are displayed in the widget</p>
                                </div>

                                <hr class="wl-section-divider" style="margin: 24px 0; border: none; border-top: 1px solid var(--sc-border);">
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Tab names & icons</label>
                                    <p class="wl-field-help" style="margin-bottom: 14px !important;">Rename tabs, choose icons, and set which tab opens first when the widget loads.</p>
                                    <div class="wl-tab-custom-list" id="wl-tab-custom-list">
                                        <?php foreach ($tab_keys as $key => $default_label): ?>
                                        <?php
                                            $label = isset($tab_labels[$key]) ? $tab_labels[$key] : $default_label;
                                            $icon = isset($tab_icons[$key]) ? $tab_icons[$key] : (isset($tab_icons_default[$key]) ? $tab_icons_default[$key] : '');
                                        ?>
                                        <div class="wl-tab-custom-row" data-tab-key="<?php echo esc_attr($key); ?>">
                                            <div class="wl-tab-custom-label-cell">
                                                <label class="wl-field-label"><?php echo esc_html($default_label); ?></label>
                                                <input type="text" name="chatzio_settings[tab_labels][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($label); ?>" class="wl-input wl-tab-label-input" placeholder="<?php echo esc_attr($default_label); ?>" maxlength="20">
                                            </div>
                                            <div class="wl-tab-custom-icon-cell">
                                                <label class="wl-field-label">Icon</label>
                                                <div class="wl-tab-icon-picker-wrap">
                                                    <input type="hidden" name="chatzio_settings[tab_icons][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($icon); ?>" class="wl-tab-icon-input" data-tab-key="<?php echo esc_attr($key); ?>">
                                                    <button type="button" class="button wl-tab-flat-icon-btn" data-tab-key="<?php echo esc_attr($key); ?>" title="Pick icon"><?php echo esc_html($icon ?: '…'); ?></button>
                                                </div>
                                            </div>
                                            <div class="wl-tab-custom-default-cell">
                                                <label class="wl-field-label">Open first</label>
                                                <label class="wl-radio-label">
                                                    <input type="radio" name="chatzio_settings[default_tab]" value="<?php echo esc_attr($key); ?>" <?php checked($default_tab, $key); ?>>
                                                    <span>Default</span>
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="wl-field-help" style="margin-top: 12px;">Custom labels appear in the widget tab bar. &quot;Open first&quot; sets which tab is visible when the chat opens.</p>
                                </div>
                            </div>
                        </section>

                        <!-- Home Tab Section (includes starters, featured products & news titles, and quick links) -->
                        <section id="wl-home" class="wl-section">
                            <div class="wl-section-header">
                                <h3>Home Tab</h3>
                                <p>Configure the welcome screen, conversation starters, featured products, and news/updates. All content shown on the Home tab is managed here.</p>
                            </div>

                            <div class="wl-home-block" data-block-id="welcome">
                                <div class="wl-home-block-header wl-home-block-toggle">
                                    <h4 class="wl-home-block-title">Welcome</h4>
                                    <span class="wl-home-block-summary"></span>
                                    <span class="wl-home-block-chevron" aria-hidden="true"></span>
                                </div>
                                <div class="wl-home-block-body">
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Welcome Headline</label>
                                    <input type="text" name="chatzio_settings[home_headline]" value="<?php echo esc_attr(isset($settings['home_headline']) ? $settings['home_headline'] : 'Hi there 👋'); ?>" class="wl-input" placeholder="Hi there 👋">
                                    <p class="wl-field-help">Main headline shown on the Home tab</p>
                                </div>
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Welcome Subtext</label>
                                    <input type="text" name="chatzio_settings[home_subtext]" value="<?php echo esc_attr(isset($settings['home_subtext']) ? $settings['home_subtext'] : 'How can we help you today?'); ?>" class="wl-input" placeholder="How can we help you today?">
                                    <p class="wl-field-help">Subtitle shown below the headline</p>
                                </div>
                                </div>
                            </div>

                            <div class="wl-home-block wl-home-block-collapsed" data-block-id="starters">
                                <div class="wl-home-block-header wl-home-block-toggle">
                                    <h4 class="wl-home-block-title">Conversation starters</h4>
                                    <span class="wl-home-block-summary"></span>
                                    <span class="wl-home-block-chevron" aria-hidden="true"></span>
                                </div>
                                <div class="wl-home-block-body">
                                <p class="wl-home-block-desc">Clickable cards that send a message when the visitor taps one.</p>
                                <div class="wl-field-group" style="margin-bottom: 16px;">
                                    <label class="wl-field-label">Default starter card</label>
                                    <p class="wl-field-help">Shown when no custom starters are added, or as the first card. Edit the default &quot;Ask a question&quot; text here.</p>
                                    <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                                        <div>
                                            <label class="wl-field-label" style="font-size: 11px; color: #646970;">Title</label>
                                            <input type="text" name="chatzio_settings[default_starter_title]" value="<?php echo esc_attr(isset($settings['default_starter_title']) ? $settings['default_starter_title'] : 'Ask a question'); ?>" class="wl-input" placeholder="Ask a question" style="min-width: 160px;">
                                        </div>
                                        <div>
                                            <label class="wl-field-label" style="font-size: 11px; color: #646970;">Subtitle</label>
                                            <input type="text" name="chatzio_settings[default_starter_subtitle]" value="<?php echo esc_attr(isset($settings['default_starter_subtitle']) ? $settings['default_starter_subtitle'] : 'We typically reply in a few minutes'); ?>" class="wl-input" placeholder="We typically reply in a few minutes" style="min-width: 200px;">
                                        </div>
                                        <div>
                                            <label class="wl-field-label" style="font-size: 11px; color: #646970;">Icon (emoji)</label>
                                            <input type="text" name="chatzio_settings[default_starter_icon]" value="<?php echo esc_attr(isset($settings['default_starter_icon']) ? $settings['default_starter_icon'] : '👋'); ?>" class="wl-input" placeholder="👋" maxlength="4" style="width: 60px;">
                                        </div>
                                    </div>
                                </div>
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Starter cards</label>
                                    <div class="chatzio-starters-list" id="chatzio-starters-list">
                                    <?php
                                    $starters = isset($settings['conversation_starters']) ? $settings['conversation_starters'] : [];
                                    if (!is_array($starters)) {
                                        $starters = json_decode($starters, true);
                                        if (!is_array($starters)) $starters = [];
                                    }
                                    if (empty($starters)) {
                                        $starters = [['title' => '', 'subtitle' => '', 'icon' => '💬']];
                                    }
                                    foreach ($starters as $idx => $starter):
                                        $title = isset($starter['title']) ? $starter['title'] : '';
                                        $subtitle = isset($starter['subtitle']) ? $starter['subtitle'] : '';
                                        $icon = isset($starter['icon']) ? $starter['icon'] : '💬';
                                    ?>
                                    <div class="chatzio-starter-row" data-index="<?php echo $idx; ?>">
                                        <div class="starter-icon-wrap">
                                            <input type="text" name="chatzio_settings[conversation_starters][<?php echo $idx; ?>][icon]" value="<?php echo esc_attr($icon); ?>" class="starter-icon-input" placeholder="💬" maxlength="4" readonly>
                                        </div>
                                        <input type="text" name="chatzio_settings[conversation_starters][<?php echo $idx; ?>][title]" value="<?php echo esc_attr($title); ?>" class="starter-title-input" placeholder="Title (e.g., Ask a question)">
                                        <input type="text" name="chatzio_settings[conversation_starters][<?php echo $idx; ?>][subtitle]" value="<?php echo esc_attr($subtitle); ?>" class="starter-subtitle-input" placeholder="Subtitle (optional)">
                                        <button type="button" class="button chatzio-remove-starter" title="Remove"><span class="dashicons dashicons-trash"></span></button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button button-secondary" id="chatzio-add-starter" style="margin-top: 12px;">
                                    <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span> Add Starter
                                </button>
                                <p class="wl-field-help" style="margin-top: 12px;">Up to 6 starters. Leave empty to show default &quot;Ask a question&quot; card.</p>
                                </div>
                                </div>
                            </div>

                            <div class="wl-home-block wl-home-block-collapsed" data-block-id="featured-products">
                                <div class="wl-home-block-header wl-home-block-toggle">
                                    <h4 class="wl-home-block-title">Featured products</h4>
                                    <span class="wl-home-block-summary"></span>
                                    <span class="wl-home-block-chevron" aria-hidden="true"></span>
                                </div>
                                <div class="wl-home-block-body">
                                <p class="wl-home-block-desc">Products shown in a block on the Home tab. Same list can power the Products tab if you choose &quot;Featured&quot; there.</p>
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Section title</label>
                                    <input type="text" name="chatzio_settings[home_featured_products_heading]" value="<?php echo esc_attr(isset($settings['home_featured_products_heading']) ? $settings['home_featured_products_heading'] : 'Featured Products'); ?>" class="wl-input" placeholder="Featured Products">
                                    <p class="wl-field-help">Label for the featured products block on the Home tab.</p>
                                </div>
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Choose products</label>
                                    <?php if (!class_exists('WooCommerce')): ?>
                                    <p class="wl-field-help">WooCommerce is required to choose products. Install and activate WooCommerce, then add products here to show them on the Home tab.</p>
                                <?php else: ?>
                                    <p class="wl-field-help" style="margin-bottom: 10px !important;">Products shown in the featured products block on the Home tab. If you set the Products tab source to &quot;Featured&quot;, the same list is used there (max 20).</p>
                                    <select name="chatzio_settings[products_featured][]" multiple class="wl-select pc-select-hidden wl-products-featured-select" size="1" aria-hidden="true">
                                        <?php if (!empty($wl_all_products)): ?>
                                            <?php foreach ($wl_all_products as $product): ?>
                                                <option value="<?php echo esc_attr($product['id']); ?>" <?php selected(in_array($product['id'], $wl_products_featured)); ?>><?php echo esc_html($product['title']); ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <div class="pc-multiselect-ui" data-select=".wl-products-featured-select">
                                        <input type="text" class="pc-multiselect-search" placeholder="Search products..." autocomplete="off">
                                        <div class="pc-multiselect-list">
                                            <?php if (empty($wl_all_products)): ?>
                                                <p class="pc-multiselect-empty">No products found.</p>
                                            <?php else: ?>
                                                <?php foreach ($wl_all_products as $product): ?>
                                                    <label class="pc-multiselect-row" data-id="<?php echo esc_attr($product['id']); ?>" data-search="<?php echo esc_attr(mb_strtolower($product['title'] . ' ' . (isset($product['sku']) ? $product['sku'] : ''))); ?>">
                                                        <input type="checkbox" value="<?php echo esc_attr($product['id']); ?>">
                                                        <span class="pc-multiselect-title"><?php echo esc_html($product['title']); ?></span>
                                                        <?php if (!empty($product['sku'])): ?><span class="pc-multiselect-sku"><?php echo esc_html($product['sku']); ?></span><?php endif; ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <p style="margin-top: 14px;">
                                    <a href="#wl-products" class="button button-secondary wl-goto-section" data-section="wl-products">
                                        <span class="dashicons dashicons-cart" style="margin-top: 3px;"></span> Products tab settings
                                    </a>
                                </p>
                                </div>
                                </div>
                            </div>

                            <div class="wl-home-block wl-home-block-collapsed" data-block-id="featured-news">
                                <div class="wl-home-block-header wl-home-block-toggle">
                                    <h4 class="wl-home-block-title">Featured news</h4>
                                    <span class="wl-home-block-summary"></span>
                                    <span class="wl-home-block-chevron" aria-hidden="true"></span>
                                </div>
                                <div class="wl-home-block-body">
                                <p class="wl-home-block-desc">Choose which news items to show on the Home tab. Add and edit news in <strong>News &amp; Updates</strong>.</p>
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Section title</label>
                                    <input type="text" name="chatzio_settings[home_news_heading]" value="<?php echo esc_attr(isset($settings['home_news_heading']) ? $settings['home_news_heading'] : 'Latest Updates'); ?>" class="wl-input" placeholder="Latest Updates">
                                    <p class="wl-field-help">Label for the news block on the Home tab.</p>
                                </div>
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Choose news to feature</label>
                                    <?php
                                    $wl_news_with_title = array_filter($wl_news_items, function($n) { return !empty(isset($n['title']) ? $n['title'] : ''); });
                                    if (empty($wl_news_with_title)): ?>
                                    <p class="wl-field-help">No news items yet. Add updates in <a href="#wl-news" class="wl-goto-section" data-section="wl-news">News &amp; Updates</a>, then return here to choose which to show on the Home tab.</p>
                                    <?php else: ?>
                                    <p class="wl-field-help" style="margin-bottom: 10px !important;">News items shown on the Home tab (up to 3). Add more in News &amp; Updates.</p>
                                    <select name="chatzio_settings[news_featured][]" multiple class="wl-select pc-select-hidden wl-news-featured-select" size="1" aria-hidden="true">
                                        <?php foreach ($wl_news_items as $nitem): if (empty($nitem['title'])) continue; $nid = isset($nitem['id']) ? $nitem['id'] : ''; if ($nid === '') continue; ?>
                                            <option value="<?php echo esc_attr($nid); ?>" <?php selected(in_array($nid, $wl_news_featured)); ?>><?php echo esc_html($nitem['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="pc-multiselect-ui" data-select=".wl-news-featured-select">
                                        <input type="text" class="pc-multiselect-search" placeholder="Search news..." autocomplete="off">
                                        <div class="pc-multiselect-list">
                                            <?php foreach ($wl_news_items as $nitem): if (empty($nitem['title'])) continue; $nid = isset($nitem['id']) ? $nitem['id'] : ''; if ($nid === '') continue; ?>
                                                <label class="pc-multiselect-row" data-id="<?php echo esc_attr($nid); ?>" data-search="<?php echo esc_attr(mb_strtolower($nitem['title'])); ?>">
                                                    <input type="checkbox" value="<?php echo esc_attr($nid); ?>">
                                                    <span class="pc-multiselect-title"><?php echo esc_html($nitem['title']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <p style="margin-top: 14px;">
                                    <a href="#wl-news" class="button button-secondary wl-goto-section" data-section="wl-news">
                                        <span class="dashicons dashicons-megaphone" style="margin-top: 3px;"></span> News &amp; Updates
                                    </a>
                                </p>
                                </div>
                            </div>
                        </section>

                        <!-- FAQ Manager Section -->
                        <section id="wl-faq" class="wl-section">
                            <div class="wl-section-header">
                                <h3>FAQ tab</h3>
                                <p>Create Q&A pairs for the FAQ tab. Visitors can browse and click to ask the bot.</p>
                            </div>
                            <div class="wl-tab-off-prompt" id="wl-faq-off-prompt" style="display: none;">
                                <p>The FAQ tab is turned off. Enable it in <strong>Tabs &amp; layout</strong> to show FAQs in the widget.</p>
                                <p style="margin-top: 12px;">
                                    <button type="button" class="button button-primary wl-enable-tab-btn" data-tab="widget_tab_faq" data-section="wl-mode">
                                        Enable FAQ tab
                                    </button>
                                </p>
                            </div>
                            <div class="wl-section-content wl-faq-content">
                            <div class="chatzio-faq-list" id="chatzio-faq-list">
                                <?php
                                $faq_items = isset($settings['faq_items']) ? $settings['faq_items'] : [];
                                if (!is_array($faq_items)) {
                                    $faq_items = json_decode($faq_items, true);
                                    if (!is_array($faq_items)) $faq_items = [];
                                }
                                if (empty($faq_items)) {
                                    $faq_items = [['question' => '', 'answer' => '', 'category' => '']];
                                }
                                foreach ($faq_items as $idx => $faq):
                                    $question = isset($faq['question']) ? $faq['question'] : '';
                                    $answer = isset($faq['answer']) ? $faq['answer'] : '';
                                    $category = isset($faq['category']) ? $faq['category'] : '';
                                ?>
                                <div class="chatzio-faq-row" data-index="<?php echo $idx; ?>">
                                    <div class="faq-row-main">
                                        <input type="text" name="chatzio_settings[faq_items][<?php echo $idx; ?>][question]" value="<?php echo esc_attr($question); ?>" class="faq-question-input" placeholder="Question (e.g., What are your shipping options?)">
                                        <input type="text" name="chatzio_settings[faq_items][<?php echo $idx; ?>][category]" value="<?php echo esc_attr($category); ?>" class="faq-category-input" placeholder="Category">
                                        <button type="button" class="button chatzio-remove-faq" title="Remove"><span class="dashicons dashicons-trash"></span></button>
                                    </div>
                                    <div class="faq-row-answer">
                                        <textarea name="chatzio_settings[faq_items][<?php echo $idx; ?>][answer]" class="faq-answer-input" placeholder="Answer (shown when visitor clicks this FAQ)..." rows="2"><?php echo esc_textarea($answer); ?></textarea>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button button-secondary" id="chatzio-add-faq" style="margin-top: 12px;">
                                <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span> Add FAQ Item
                            </button>
                            <p class="wl-field-help" style="margin-top: 12px;">Up to 20 items. Categories help organize FAQs into filterable groups.</p>
                            </div>
                        </section>

                        <!-- Products Control Section -->
                        <section id="wl-products" class="wl-section">
                            <div class="wl-section-header">
                                <h3>Products tab</h3>
                                <p>Choose what the Products tab shows. Featured products for the Home tab are set in the Home tab section.</p>
                            </div>
                            <div class="wl-tab-off-prompt" id="wl-products-off-prompt" style="display: none;">
                                <p>The Products tab is turned off. Enable it in <strong>Tabs &amp; layout</strong> to show products in the widget.</p>
                                <p style="margin-top: 12px;">
                                    <button type="button" class="button button-primary wl-enable-tab-btn" data-tab="widget_tab_products" data-section="wl-mode">
                                        Enable Products tab
                                    </button>
                                </p>
                            </div>
                            <div class="wl-section-content wl-products-content">
                            <?php if (!class_exists('WooCommerce')): ?>
                                <div class="chatzio-notice chatzio-notice-warning">
                                    <p><strong>WooCommerce is not active.</strong> Product controls require WooCommerce to be installed and activated.</p>
                                </div>
                            <?php else: ?>
                                <?php
                                $all_products = $wl_all_products;
                                $products_featured = $wl_products_featured;
                                // Get WooCommerce product categories
                                $product_categories = get_terms([
                                    'taxonomy' => 'product_cat',
                                    'hide_empty' => false,
                                ]);
                                // Get saved settings
                                $products_tab_source = isset($settings['products_tab_source']) && in_array($settings['products_tab_source'], ['category', 'featured', 'bestsellers', 'onsale', 'specific_products'], true) ? $settings['products_tab_source'] : 'category';
                                $products_categories = isset($settings['products_categories']) && is_array($settings['products_categories']) ? array_map('intval', $settings['products_categories']) : [];
                                // Default: all categories selected when none saved
                                if (empty($products_categories) && !empty($product_categories) && !is_wp_error($product_categories)) {
                                    $products_categories = array_map(function($c) { return (int) $c->term_id; }, $product_categories);
                                }
                                $products_tags = isset($settings['products_tags']) && is_array($settings['products_tags']) ? $settings['products_tags'] : [];
                                $products_tags_text = implode("\n", $products_tags);
                                $products_highlight = isset($settings['products_highlight']) && is_array($settings['products_highlight']) ? array_map('intval', $settings['products_highlight']) : [];
                                $products_tab_heading = isset($settings['products_tab_heading']) ? $settings['products_tab_heading'] : 'Browse Products';
                                ?>

                                <!-- Products tab heading (shown in widget) -->
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Products tab heading</label>
                                    <input type="text" name="chatzio_settings[products_tab_heading]" value="<?php echo esc_attr($products_tab_heading); ?>" class="wl-input" placeholder="Browse Products" style="max-width: 280px;">
                                    <p class="wl-field-help">Title shown at the top of the Products tab in the widget.</p>
                                </div>

                                <!-- Products tab source -->
                                <div class="wl-field-group">
                                    <label class="wl-field-label">Products tab source</label>
                                    <p class="wl-field-help" style="margin-bottom: 14px !important;">Choose what to display. Max 20 products are shown; a shop link appears when there are more.</p>

                                    <div class="pc-source-grid" id="products-tab-source">
                                        <?php
                                        $source_options = [
                                            'category'          => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z"/></svg>', 'label' => 'By category', 'desc' => 'Show products grouped by category'],
                                            'featured'          => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>', 'label' => 'Featured', 'desc' => 'Hand-picked featured products'],
                                            'bestsellers'       => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.468 5.99 5.99 0 0 0-1.925 3.547 5.975 5.975 0 0 1-2.133-1.001A3.75 3.75 0 0 0 12 18Z"/></svg>', 'label' => 'Best sellers', 'desc' => 'Top selling products'],
                                            'onsale'            => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/></svg>', 'label' => 'On sale', 'desc' => 'Products with active discounts'],
                                            'specific_products' => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>', 'label' => 'Specific products', 'desc' => 'Choose exactly which products to show'],
                                        ];
                                        foreach ($source_options as $val => $opt): ?>
                                            <label class="pc-source-card<?php echo $products_tab_source === $val ? ' pc-source-active' : ''; ?>">
                                                <input type="radio" name="chatzio_settings[products_tab_source]" value="<?php echo esc_attr($val); ?>" <?php checked($products_tab_source, $val); ?>>
                                                <span class="pc-source-icon"><?php echo $opt['icon']; ?></span>
                                                <span class="pc-source-label"><?php echo esc_html($opt['label']); ?></span>
                                                <span class="pc-source-desc"><?php echo esc_html($opt['desc']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- By category: categories to display (default all) -->
                                <div class="wl-field-group wl-products-source-option pc-source-panel" data-source="category">
                                    <label class="wl-field-label">Categories to display</label>
                                    <div class="pc-category-list">
                                        <?php if (empty($product_categories) || is_wp_error($product_categories)): ?>
                                            <p class="description">No product categories found.</p>
                                        <?php else: ?>
                                            <label class="pc-cat-item pc-cat-all">
                                                <input type="checkbox" id="products-categories-all" class="wl-checkbox-all">
                                                <span class="pc-cat-name">Select All</span>
                                            </label>
                                            <?php foreach ($product_categories as $cat): ?>
                                                <label class="pc-cat-item">
                                                    <input type="checkbox" name="chatzio_settings[products_categories][]" value="<?php echo esc_attr($cat->term_id); ?>" class="wl-checkbox-item wl-products-category-cb" <?php checked(in_array($cat->term_id, $products_categories)); ?>>
                                                    <span class="pc-cat-name"><?php echo esc_html($cat->name); ?></span>
                                                    <span class="pc-cat-count"><?php echo esc_html($cat->count); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <p class="wl-field-help">Only selected categories appear in the Products tab.</p>
                                </div>

                                <!-- Featured: uses Home tab list (info only) -->
                                <div class="wl-field-group wl-products-source-option pc-source-panel pc-source-info" data-source="featured">
                                    <div class="pc-info-box">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                                        <span>Shows the same products as the Featured products list in the <strong>Home tab</strong>. Max 20 in the Products tab.</span>
                                    </div>
                                </div>

                                <!-- Best sellers / On sale: info only -->
                                <div class="wl-field-group wl-products-source-option pc-source-panel pc-source-info" data-source="bestsellers">
                                    <div class="pc-info-box">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                                        <span>Top 20 best-selling products will be shown automatically based on WooCommerce sales data.</span>
                                    </div>
                                </div>
                                <div class="wl-field-group wl-products-source-option pc-source-panel pc-source-info" data-source="onsale">
                                    <div class="pc-info-box">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                                        <span>Up to 20 products currently on sale will be shown automatically from WooCommerce.</span>
                                    </div>
                                </div>

                                <!-- Specific products -->
                                <div class="wl-field-group wl-products-source-option pc-source-panel" data-source="specific_products">
                                    <label class="wl-field-label">Choose products</label>
                                    <select name="chatzio_settings[products_highlight][]" multiple class="wl-select pc-select-hidden wl-products-specific-select" size="1" aria-hidden="true">
                                        <?php if (!empty($all_products)): ?>
                                            <?php foreach ($all_products as $product): ?>
                                                <option value="<?php echo esc_attr($product['id']); ?>" <?php selected(in_array($product['id'], $products_highlight)); ?>><?php echo esc_html($product['title']); ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <div class="pc-multiselect-ui" data-select=".wl-products-specific-select">
                                        <input type="text" class="pc-multiselect-search" placeholder="Search products..." autocomplete="off">
                                        <div class="pc-multiselect-list">
                                            <?php if (empty($all_products)): ?>
                                                <p class="pc-multiselect-empty">No products found.</p>
                                            <?php else: ?>
                                                <?php foreach ($all_products as $product): ?>
                                                    <label class="pc-multiselect-row" data-id="<?php echo esc_attr($product['id']); ?>" data-search="<?php echo esc_attr(mb_strtolower($product['title'] . ' ' . (isset($product['sku']) ? $product['sku'] : ''))); ?>">
                                                        <input type="checkbox" value="<?php echo esc_attr($product['id']); ?>">
                                                        <span class="pc-multiselect-title"><?php echo esc_html($product['title']); ?></span>
                                                        <?php if (!empty($product['sku'])): ?><span class="pc-multiselect-sku"><?php echo esc_html($product['sku']); ?></span><?php endif; ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <p class="wl-field-help">Tick the products to show. Only these will appear in the Products tab (max 20).</p>
                                </div>

                            <?php endif; ?>
                            </div>
                        </section>

                        <!-- News & Updates Section -->
                        <section id="wl-news" class="wl-section">
                            <div class="wl-section-header">
                                <h3>News &amp; Updates</h3>
                                <p>Add and edit news or updates. Choose which to show on the Home tab in the <strong>Home tab</strong> section (Featured news).</p>
                            </div>
                            <div class="wl-field-group">
                                <label class="wl-field-label">Updates</label>
                                <div class="chatzio-news-list" id="chatzio-news-list">
                                    <?php
                                    if (empty($wl_news_items)) {
                                        $wl_news_items = [['id' => 'n' . uniqid(), 'title' => '', 'description' => '', 'url' => '', 'date' => '', 'image_url' => '']];
                                    }
                                    foreach ($wl_news_items as $idx => $item):
                                        $title = isset($item['title']) ? $item['title'] : '';
                                        $desc = isset($item['description']) ? $item['description'] : '';
                                        $url = isset($item['url']) ? $item['url'] : '';
                                        $date = isset($item['date']) ? $item['date'] : '';
                                        $image_url = isset($item['image_url']) ? $item['image_url'] : '';
                                        $item_id = isset($item['id']) ? $item['id'] : 'n' . uniqid();
                                    ?>
                                    <div class="chatzio-news-row" data-index="<?php echo $idx; ?>">
                                        <input type="hidden" name="chatzio_settings[news_items][<?php echo $idx; ?>][id]" value="<?php echo esc_attr($item_id); ?>" class="news-id-input">
                                        <div class="news-row-main">
                                            <div class="news-row-image-cell">
                                                <input type="hidden" name="chatzio_settings[news_items][<?php echo $idx; ?>][image_url]" value="<?php echo esc_attr($image_url); ?>" class="news-image-url-input">
                                                <button type="button" class="button chatzio-news-upload-image" title="Add image"><?php echo $image_url ? '<span class="dashicons dashicons-edit"></span> Change' : '<span class="dashicons dashicons-format-image"></span> Upload'; ?></button>
                                                <?php if ($image_url): ?>
                                                <div class="news-image-preview"><img src="<?php echo esc_url($image_url); ?>" alt=""></div>
                                                <?php endif; ?>
                                            </div>
                                            <input type="text" name="chatzio_settings[news_items][<?php echo $idx; ?>][title]" value="<?php echo esc_attr($title); ?>" class="news-title-input" placeholder="Title (eg: New feature launched!)">
                                            <input type="text" name="chatzio_settings[news_items][<?php echo $idx; ?>][description]" value="<?php echo esc_attr($desc); ?>" class="news-desc-input" placeholder="Description (eg: Brief summary)">
                                            <input type="text" name="chatzio_settings[news_items][<?php echo $idx; ?>][url]" value="<?php echo esc_attr($url); ?>" class="news-url-input" placeholder="Link URL (eg: https://example.com)">
                                        </div>
                                        <div class="news-row-meta">
                                            <div>
                                                <input type="text" name="chatzio_settings[news_items][<?php echo $idx; ?>][date]" value="<?php echo esc_attr($date); ?>" class="news-date-input" placeholder="Badge (eg: New, Sale)">
                                            </div>
                                            <button type="button" class="button chatzio-remove-news" title="Remove"><span class="dashicons dashicons-trash"></span></button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button button-secondary" id="chatzio-add-news" style="margin-top: 12px;">
                                    <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span> Add update
                                </button>
                                <p class="wl-field-help" style="margin-top: 12px;">Up to 6 items. Use <strong>Home tab → Featured news</strong> to choose which appear on the Home tab.</p>
                            </div>
                        </section>

                    </div>
                </div>
            </div>

            <!-- Lead Capture Tab -->
            <div id="leads" class="tab-content">
                <h2>Lead Capture Settings</h2>

                <div class="chatzio-notice">
                    <p><strong>Turn conversations into leads.</strong> Capture visitor emails through a pre-chat form.</p>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            Pre-Chat Form
                            <span class="chatzio-tooltip">?<span class="tooltip-text">Shows a form overlay before chat starts. Visitors enter their info before they can chat. Great for qualifying leads upfront.</span></span>
                        </th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[enable_lead_form]" value="1" <?php checked(isset($settings['enable_lead_form']) ? $settings['enable_lead_form'] : false); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Show a lead capture form before the chat conversation starts</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Form Heading</th>
                        <td>
                            <input type="text" name="chatzio_settings[lead_form_heading]" value="<?php echo esc_attr(isset($settings['lead_form_heading']) ? $settings['lead_form_heading'] : 'Before we start, tell us about yourself'); ?>" class="regular-text">
                            <p class="description">Main heading displayed at the top of the form</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Form Subheading</th>
                        <td>
                            <input type="text" name="chatzio_settings[lead_form_subheading]" value="<?php echo esc_attr(isset($settings['lead_form_subheading']) ? $settings['lead_form_subheading'] : "We'd love to know who we're chatting with."); ?>" class="regular-text">
                            <p class="description">Optional subheading displayed below the main heading</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Form Fields</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" checked disabled> Email (always required)
                                </label><br>
                                <label>
                                    <input type="checkbox" name="chatzio_settings[lead_form_name]" value="1" <?php checked(isset($settings['lead_form_name']) ? $settings['lead_form_name'] : true); ?>>
                                    Name
                                </label><br>
                                <label>
                                    <input type="checkbox" name="chatzio_settings[lead_form_phone]" value="1" <?php checked(isset($settings['lead_form_phone']) ? $settings['lead_form_phone'] : false); ?>>
                                    Phone
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Allow Skip</th>
                        <td>
                            <label class="chatzio-switch">
                                <?php
                                $lead_form_allow_skip = isset($settings['lead_form_allow_skip']) ? (bool) $settings['lead_form_allow_skip'] : true;
                                ?>
                                <input type="checkbox" name="chatzio_settings[lead_form_allow_skip]" value="1" <?php checked($lead_form_allow_skip, true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">If enabled, visitors can skip the lead form and start chatting without sharing their details.</p>
                        </td>
                    </tr>
                    <?php /* Smart In-Chat Capture - Disabled but keeping code for future use
                    <tr>
                        <th scope="row">
                            Smart In-Chat Capture
                            <span class="chatzio-tooltip">?<span class="tooltip-text">The AI naturally asks for the visitor's email when it detects buying interest. When the visitor shares their email in conversation, it is automatically captured as a lead.</span></span>
                        </th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[enable_smart_capture]" value="1" <?php checked(isset($settings['enable_smart_capture']) ? $settings['enable_smart_capture'] : true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">AI will naturally ask for email when visitors show buying interest. Emails mentioned in chat are auto-captured.</p>
                        </td>
                    </tr>
                    */ ?>
                </table>
            </div>

            <!-- Notifications Tab -->
            <div id="notifications" class="tab-content">
                <h2>Email Notifications</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">Notification Email</th>
                        <td>
                            <input type="email" name="chatzio_settings[notification_email]" value="<?php echo esc_attr(isset($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email')); ?>" class="regular-text">
                            <p class="description">Email address to receive notifications. Defaults to admin email.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Notify on New Lead</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[notify_new_lead]" value="1" <?php checked(isset($settings['notify_new_lead']) ? $settings['notify_new_lead'] : true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Send an email when a new lead is captured</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Notify on New Conversation</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[notify_new_conversation]" value="1" <?php checked(isset($settings['notify_new_conversation']) ? $settings['notify_new_conversation'] : false); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Send an email when a new chat session starts (rate-limited to 1 per session per hour)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Notify on Failed Answer</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[notify_failed_query]" value="1" <?php checked(!empty($settings['notify_failed_query'])); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Get an instant email when the chatbot can't answer a customer's question, so you can reach out to them directly (rate-limited to 1 per session per hour)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Notify on Restock</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[notify_restock]" value="1" <?php checked(!empty($settings['notify_restock'])); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Lets visitors leave their email on out-of-stock products to be auto-notified when restocked. The button appears automatically on product cards in chat when the item is out of stock. To show it on your product pages too, add the shortcode <code>[chatzio_restock]</code> anywhere on the page. Requires WooCommerce.</p>
                        </td>
                    </tr>
                    <tr class="chatzio-notify-topics-row" style="<?php echo (empty($settings['notify_new_conversation']) && empty($settings['notify_failed_query'])) ? 'display:none;' : ''; ?>">
                        <th scope="row">Notify for Topics</th>
                        <td>
                            <?php
                            $notify_topics = isset($settings['notify_topics']) ? (array) $settings['notify_topics'] : [];
                            $all_topics = class_exists('Chatzio_Topic_Resolver') ? Chatzio_Topic_Resolver::get_registry() : [];
                            $all_topics[] = 'general';
                            $is_all = empty($notify_topics) || count($notify_topics) === count($all_topics);
                            ?>
                            <div class="chatzio-topic-checkboxes" style="display:flex;flex-wrap:wrap;gap:8px 16px;">
                                <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:600;">
                                    <input type="checkbox" id="chatzio-notify-all-topics" <?php checked($is_all); ?>>
                                    All
                                </label>
                                <?php foreach ($all_topics as $t): ?>
                                    <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;">
                                        <input type="checkbox" class="chatzio-notify-topic-cb" name="chatzio_settings[notify_topics][]" value="<?php echo esc_attr($t); ?>" <?php checked($is_all || in_array($t, $notify_topics, true)); ?>>
                                        <?php echo esc_html(ucfirst($t)); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Data Retention</th>
                        <td>
                            <input type="number" name="chatzio_settings[data_retention_days]" value="<?php echo esc_attr(isset($settings['data_retention_days']) ? $settings['data_retention_days'] : '0'); ?>" min="0" max="365" class="small-text">
                            <span> days</span>
                            <p class="description">Auto-delete conversations older than this. Set to 0 to keep forever.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Advanced Tab -->
            <div id="advanced" class="tab-content">
                <h2>Advanced Settings</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">AI Order Status Tools</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[enable_ai_order_tools]" value="1" <?php checked(isset($settings['enable_ai_order_tools']) ? $settings['enable_ai_order_tools'] : true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Let AI route order conversations while WooCommerce securely verifies ownership and renders order status and tracking. Billing emails remain server-side.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-Sync Content</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[auto_sync_enabled]" value="1" <?php checked(isset($settings['auto_sync_enabled']) ? $settings['auto_sync_enabled'] : true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Automatically sync content daily</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Sync Content Types</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="chatzio_settings[sync_posts]" value="1" <?php checked(isset($settings['sync_posts']) ? $settings['sync_posts'] : true); ?>>
                                    Posts
                                </label><br>
                                <label>
                                    <input type="checkbox" name="chatzio_settings[sync_pages]" value="1" <?php checked(isset($settings['sync_pages']) ? $settings['sync_pages'] : true); ?>>
                                    Pages
                                </label><br>
                                <label>
                                    <input type="checkbox" name="chatzio_settings[sync_products]" value="1" <?php checked(isset($settings['sync_products']) ? $settings['sync_products'] : true); ?>>
                                    Products (WooCommerce)
                                </label>
                            </fieldset>
                            <p class="description">Choose which content types to include in the chatbot's knowledge</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[debug_mode]" value="1" <?php checked(isset($settings['debug_mode']) ? $settings['debug_mode'] : false); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Enable detailed logging for troubleshooting</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            Search Mode
                            <span class="chatzio-tooltip">?<span class="tooltip-text">Vector search uses AI embeddings for semantic matching but requires an extra API call (~500-800ms). FULLTEXT is faster but matches keywords only. Hybrid combines both.</span></span>
                        </th>
                        <td>
                            <?php $search_mode = isset($settings['search_mode']) ? $settings['search_mode'] : 'hybrid'; ?>
                            <select name="chatzio_settings[search_mode]">
                                <option value="hybrid" <?php selected($search_mode, 'hybrid'); ?>>Hybrid (Vector + FULLTEXT) — Best quality, slower</option>
                                <option value="fulltext" <?php selected($search_mode, 'fulltext'); ?>>FULLTEXT Only — Fast, keyword-based</option>
                                <option value="vector" <?php selected($search_mode, 'vector'); ?>>Vector Only — Semantic, moderate speed</option>
                            </select>
                            <p class="description">
                                <strong>Performance tip:</strong> If responses feel slow, try "FULLTEXT Only" to skip the embedding API call (~1s faster).<br>
                                Current context_build time is mostly spent generating query embeddings and computing similarity.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Hide All Admin Notices</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[hide_all_notices]" value="1" <?php checked(isset($settings['hide_all_notices']) ? $settings['hide_all_notices'] : true); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">Hide all WordPress and plugin notices (update alerts, opt-in banners, etc.) on Chatzio admin pages for a cleaner interface.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Reduce Top Gap (Frontend)</th>
                        <td>
                            <label class="chatzio-switch">
                                <input type="checkbox" name="chatzio_settings[reduce_top_gap]" value="1" <?php checked(isset($settings['reduce_top_gap']) ? $settings['reduce_top_gap'] : false); ?>>
                                <span class="slider"></span>
                            </label>
                            <p class="description">When logged in, the WordPress admin bar can create a large gap below the browser. Enable this to tighten top spacing on the frontend (only applies when the admin bar is visible). Disable if your theme has a tall header.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Custom Admin CSS</th>
                        <td>
                            <textarea name="chatzio_settings[admin_custom_css]" rows="10" class="large-text chatzio-custom-css" placeholder="/* Add your custom CSS here to override admin panel styles */&#10;&#10;.wrap.chatzio-admin .your-selector {&#10;    property: value !important;&#10;}"><?php echo esc_textarea(isset($settings['admin_custom_css']) ? $settings['admin_custom_css'] : ''); ?></textarea>
                            <p class="description">
                                <strong>Custom CSS overrides for the admin panel.</strong> Use this to fine-tune styles if needed.<br>
                                All selectors should start with <code>.wrap.chatzio-admin</code> for specificity. Use <code>!important</code> for guaranteed priority.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <!-- Dummy Data Generator -->
                <div class="chatzio-dummy-data-section chatzio-card" style="margin-top: 24px;">
                    <h3 style="margin-top: 0;">
                        <span class="dashicons dashicons-database"></span>
                        Generate Dummy Data
                    </h3>
                    <p class="description">
                        Generate realistic dummy data for conversations, leads, and analytics to see how your dashboard looks with data populated. 
                        This is useful for testing and demonstrations. You can reset all data anytime using the "Reset Plugin" button below.
                    </p>
                    <button type="button" id="chatzio-generate-dummy-data" class="button button-secondary">
                        <span class="dashicons dashicons-database-add"></span>
                        Generate Dummy Data
                    </button>
                    <div id="chatzio-dummy-data-result" class="chatzio-dummy-data-result" style="display: none; margin-top: 12px;"></div>
                </div>
                
                <!-- Danger Zone -->
                <div class="chatzio-danger-zone">
                <h3 class="danger-zone-title">
                    <span class="dashicons dashicons-warning"></span>
                    Danger Zone
                </h3>
                <div class="danger-zone-content">
                    <p class="danger-zone-description">
                        <strong>Reset Plugin to Defaults</strong><br>
                        This will permanently delete all plugin data and reset all settings to default values. This action cannot be undone.
                    </p>
                    <ul class="danger-zone-list">
                        <li>All conversations will be deleted</li>
                        <li>All leads will be deleted</li>
                        <li>All analytics data will be deleted</li>
                        <li>All synced content will be deleted</li>
                        <li>All uploaded resources will be deleted</li>
                        <li>All logs will be deleted</li>
                        <li>All settings will be reset to defaults</li>
                    </ul>
                    <button type="button" id="chatzio-reset-plugin" class="button button-secondary chatzio-reset-btn">
                        <span class="dashicons dashicons-update"></span>
                        Reset Plugin to Defaults
                    </button>
                    <div id="chatzio-reset-result" class="chatzio-reset-result" style="display: none;"></div>
                </div>
                </div>
            </div>
            
            <div style="padding: 0 24px 24px; margin-top: 24px;">
                <?php submit_button('Save Settings', 'primary large', 'submit', true, ['style' => 'margin-top:0;']); ?>
            </div>
        </form>
        <script>
        (function(){try{var t=sessionStorage.getItem("chatzio_settings_tab");if(t){var tabs=document.querySelectorAll(".nav-tab");for(var i=0;i<tabs.length;i++){if(tabs[i].getAttribute("href")===t)tabs[i].classList.add("nav-tab-active");else tabs[i].classList.remove("nav-tab-active");}var panes=document.querySelectorAll(".tab-content");for(var j=0;j<panes.length;j++){if("#"+panes[j].id===t)panes[j].classList.add("active");else panes[j].classList.remove("active");}}}catch(e){}})();
        
        // Products Control: mutually exclusive source + Select All
        jQuery(document).ready(function($) {
            // Widget position: show custom px inputs only when "Custom" is selected
            var $positionSelect = $('#chatzio_widget_position');
            var $customWrap = $('#chatzio_custom_position_wrap');
            function toggleCustomPositionWrap() {
                $customWrap.toggle($positionSelect.val() === 'custom');
            }
            $positionSelect.on('change', toggleCustomPositionWrap);
            toggleCustomPositionWrap();

            var $source = $('#products-tab-source input[name="chatzio_settings[products_tab_source]"]');
            var $opts = $('.wl-products-source-option');
            var $cards = $('#products-tab-source .pc-source-card');

            function updateProductsSourceVisibility() {
                var src = $source.filter(':checked').val() || 'category';
                $opts.each(function() {
                    var $el = $(this);
                    $el.toggle($el.data('source') === src);
                });
                $cards.removeClass('pc-source-active');
                $cards.has('input:checked').addClass('pc-source-active');
            }

            $source.on('change', updateProductsSourceVisibility);
            updateProductsSourceVisibility();

            $('#products-categories-all').on('change', function() {
                var checked = $(this).is(':checked');
                $('.wl-products-category-cb').prop('checked', checked);
            });

            $('.wl-products-category-cb').on('change', function() {
                var total = $('.wl-products-category-cb').length;
                var checked = $('.wl-products-category-cb:checked').length;
                $('#products-categories-all').prop('checked', total > 0 && checked === total);
            });

            var total = $('.wl-products-category-cb').length;
            var checked = $('.wl-products-category-cb:checked').length;
            if (total > 0 && checked === total) {
                $('#products-categories-all').prop('checked', true);
            }

            // Product multiselect UI: sync checkboxes with hidden select
            $('.pc-multiselect-ui').each(function() {
                var $ui = $(this);
                var $select = $ui.closest('.wl-field-group').find($ui.data('select'));
                var $rows = $ui.find('.pc-multiselect-row');
                var $search = $ui.find('.pc-multiselect-search');

                function syncFromSelect() {
                    var selected = $select.val() || [];
                    $rows.each(function() {
                        var id = String($(this).data('id'));
                        $(this).find('input[type="checkbox"]').prop('checked', selected.indexOf(id) !== -1);
                    });
                }
                function syncToSelect() {
                    var ids = [];
                    $rows.find('input[type="checkbox"]:checked').each(function() { ids.push($(this).val()); });
                    $select.find('option').prop('selected', false);
                    ids.forEach(function(id) { $select.find('option[value="' + id + '"]').prop('selected', true); });
                }

                syncFromSelect();
                $rows.find('input[type="checkbox"]').on('change', syncToSelect);

                $search.on('input', function() {
                    var q = ($search.val() || '').toLowerCase().trim();
                    $rows.each(function() {
                        var show = !q || ($(this).data('search') || '').indexOf(q) !== -1;
                        $(this).toggle(show);
                    });
                });
            });

            // Generate dummy data
            $('#chatzio-generate-dummy-data').on('click', function() {
                var $btn = $(this);
                var $result = $('#chatzio-dummy-data-result');
                
                if (!confirm('This will generate dummy data for conversations, leads, and analytics. Continue?')) {
                    return;
                }
                
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Generating...');
                $result.show().html('<p class="description">Generating dummy data, please wait...</p>');
                
                $.ajax({
                    url: chatzioAdmin.ajaxUrl,
                    type: 'POST',
                    timeout: 120000,
                    data: {
                        action: 'chatzio_generate_dummy_data',
                        nonce: chatzioAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="chatzio-alert chatzio-alert-success"><strong>Success!</strong> ' + (response.data.message || 'Dummy data generated successfully.') + '</div>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $result.html('<div class="chatzio-alert chatzio-alert-error"><strong>Error:</strong> ' + (response.data && response.data.message ? response.data.message : 'Failed to generate dummy data') + '</div>');
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-database-add"></span> Generate Dummy Data');
                        }
                    },
                    error: function(xhr) {
                        var detail = xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
                        $result.html('<div class="chatzio-alert chatzio-alert-error"><strong>Request failed' + detail + '.</strong> Please try again.</div>');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-database-add"></span> Generate Dummy Data');
                    }
                });
            });
        });
        </script>
        <script>
        jQuery(function($) {
            function updateTopicsRowVisibility() {
                var showTopics = $('input[name="chatzio_settings[notify_new_conversation]"]').is(':checked')
                              || $('input[name="chatzio_settings[notify_failed_query]"]').is(':checked');
                $('.chatzio-notify-topics-row').toggle(showTopics);
            }
            $('input[name="chatzio_settings[notify_new_conversation]"], input[name="chatzio_settings[notify_failed_query]"]').on('change', updateTopicsRowVisibility);

            // "All" checkbox toggles individual topic checkboxes
            var $all = $('#chatzio-notify-all-topics');
            var $topics = $('.chatzio-notify-topic-cb');

            $all.on('change', function() {
                $topics.prop('checked', this.checked);
            });

            // If any individual topic is unchecked, uncheck "All"; if all checked, check "All"
            $topics.on('change', function() {
                $all.prop('checked', $topics.length === $topics.filter(':checked').length);
            });
        });
        </script>
        </div>
    </div>
</div>
