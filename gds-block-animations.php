<?php
/**
 * Plugin Name: GDS Block Animations
 * Plugin URI: https://example.com
 * Description: Animate WordPress blocks on scroll with customizable settings.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: gds-block-animations
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Retrieve enabled blocks with backwards-compatibility for legacy option names.
 *
 * @return array
 */
function gdsBlockAnimationsGetEnabledBlocks()
{
    $enabledBlocks = get_option('gds_block_animations_blocks', null);

    if ($enabledBlocks === null) {
        $enabledBlocks = get_option('gds_animate_blocks', []);
    }

    if (!is_array($enabledBlocks)) {
        $enabledBlocks = [];
    }

    return $enabledBlocks;
}

/**
 * Walk parsed blocks recursively and collect block names.
 *
 * @param array $blocks
 * @param array $usedBlockNames
 *
 * @return void
 */
function gdsBlockAnimationsCollectBlockNames(array $blocks, array &$usedBlockNames)
{
    foreach ($blocks as $block) {
        if (!empty($block['blockName'])) {
            $usedBlockNames[$block['blockName']] = true;
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            gdsBlockAnimationsCollectBlockNames($block['innerBlocks'], $usedBlockNames);
        }
    }
}

/**
 * Get all block names currently used on published pages.
 *
 * @return array<string, bool>
 */
function gdsBlockAnimationsGetUsedBlockNamesOnPages()
{
    $usedBlockNames = [];

    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);

    foreach ($pages as $pageId) {
        $content = get_post_field('post_content', $pageId);
        if (!is_string($content) || $content === '' || !has_blocks($content)) {
            continue;
        }

        $blocks = parse_blocks($content);
        gdsBlockAnimationsCollectBlockNames($blocks, $usedBlockNames);
    }

    return $usedBlockNames;
}

/**
 * Option key for site-wide animation CSS stored in the database.
 */
function gdsBlockAnimationsGetGlobalCssOptionName()
{
    return 'gds_block_animations_global_css';
}

/**
 * Option key: skip animation setup for blocks visible in the initial viewport (above the fold).
 *
 * @return string
 */
function gdsBlockAnimationsGetSkipAboveFoldOptionName()
{
    return 'gds_block_animations_skip_above_fold';
}

/**
 * Whether to skip scroll animation for blocks intersecting the initial viewport.
 *
 * @return bool
 */
function gdsBlockAnimationsGetSkipAboveFold()
{
    return (bool) get_option(gdsBlockAnimationsGetSkipAboveFoldOptionName(), false);
}

/**
 * One-time: if Claude key is empty but the old Gemini option holds an Anthropic key (sk-ant-…), copy it.
 */
function gdsBlockAnimationsMaybeMigrateOptionsToClaude()
{
    if (get_option('gds_block_animations_claude_options_migrated', '') === '1') {
        return;
    }

    $claudeKey = get_option('gds_block_animations_claude_api_key', '');
    if (!is_string($claudeKey) || $claudeKey === '') {
        $legacyGeminiSlot = get_option('gds_block_animations_gemini_api_key', '');
        $trimLegacy = trim($legacyGeminiSlot);
        if ($trimLegacy !== '' && strpos($trimLegacy, 'sk-ant') === 0) {
            update_option('gds_block_animations_claude_api_key', $trimLegacy, false);
        }
    }

    $claudeModel = get_option('gds_block_animations_claude_model', '');
    if (!is_string($claudeModel) || $claudeModel === '') {
        $legacyGeminiModel = get_option('gds_block_animations_gemini_model', '');
        if (is_string($legacyGeminiModel) && $legacyGeminiModel !== '' && stripos($legacyGeminiModel, 'claude') !== false) {
            update_option('gds_block_animations_claude_model', $legacyGeminiModel, false);
        }
    }

    update_option('gds_block_animations_claude_options_migrated', '1', false);
}
add_action('admin_init', 'gdsBlockAnimationsMaybeMigrateOptionsToClaude', 5);

/**
 * Anthropic Claude API key (wp-config constant takes precedence).
 *
 * @return string
 */
function gdsBlockAnimationsGetClaudeApiKey()
{
    if (defined('GDS_BLOCK_ANIMATIONS_CLAUDE_KEY')) {
        $fromConfig = constant('GDS_BLOCK_ANIMATIONS_CLAUDE_KEY');
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }
    }

    $key = get_option('gds_block_animations_claude_api_key', '');

    return is_string($key) ? $key : '';
}

/**
 * Preserve Claude API key when the field was omitted from POST or left blank (options.php updates all registered keys).
 *
 * @param mixed  $value
 * @param mixed  $oldValue
 * @param string $option
 *
 * @return mixed
 */
function gdsBlockAnimationsPreUpdateClaudeApiKey($value, $oldValue, $option)
{
    unset($option);

    if (!empty($_POST['gds_block_animations_clear_claude_key'])) {
        return '';
    }

    if ($value === null || $value === false) {
        return $oldValue;
    }

    if (!is_string($value)) {
        return $oldValue;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        $hadStored = is_string($oldValue) && $oldValue !== '';
        if ($hadStored) {
            return $oldValue;
        }

        return '';
    }

    return $trimmed;
}
add_filter(
    'pre_update_option_gds_block_animations_claude_api_key',
    'gdsBlockAnimationsPreUpdateClaudeApiKey',
    10,
    3
);

/**
 * Claude model id for CSS generation (Messages API).
 *
 * @return string
 */
function gdsBlockAnimationsGetClaudeModel()
{
    $defaultModel = 'claude-sonnet-4-20250514';
    $model = get_option('gds_block_animations_claude_model', $defaultModel);
    if (!is_string($model) || $model === '') {
        return $defaultModel;
    }
    if (
        stripos($model, 'gemini') !== false
        || stripos($model, 'gpt') !== false
    ) {
        return $defaultModel;
    }

    return $model;
}

/**
 * Strip markdown code fences from model output if present.
 *
 * @param string $text
 *
 * @return string
 */
function gdsBlockAnimationsExtractCssFromLlmReply($text)
{
    if (!is_string($text)) {
        return '';
    }
    $text = trim($text);
    if (preg_match('/```(?:css)?\s*([\s\S]*?)```/i', $text, $matches)) {
        return trim($matches[1]);
    }

    return $text;
}

/**
 * Fixed system context for Claude (animation classes + Gutenberg markup). Sent with every request.
 *
 * @return string
 */
function gdsBlockAnimationsGetClaudeSystemContext()
{
    $context = <<<'GDS_BA_CLAUDE_CONTEXT'
You are writing CSS for a WordPress plugin that animates blocks on scroll. Output only valid CSS: no markdown, no code fences, no prose, no HTML.

## Animation classes (always use these names exactly)
- gds-block-animations-block — Added by PHP to enabled blocks’ outer wrapper before scroll-in. Use this for the “hidden / waiting” visual state (e.g. opacity 0, translateY).
- gds-animate-visible — Added by the front-end script when the block intersects the viewport (entrance transitions run when the user was scrolling down; scrolling up reveals with transitions suppressed briefly).
- gds-block-animations-instant — Temporary class used internally when revealing while scrolling up; do not target it in site CSS.
- gds-block-animations-visible — Also added with gds-animate-visible for compatibility; you may target either or both.

Typical pair:
  .wp-block-… .gds-block-animations-block { /* initial state */ }
  .wp-block-… .gds-animate-visible { /* visible state */ }
Or use .gds-block-animations-visible where you need the same hook.

Optional site setting removes gds-block-animations-block from blocks that intersect the first viewport and adds gds-animate-visible immediately so they stay visible. Still, always scope the “hidden / waiting” state to .gds-block-animations-block on the same animated wrapper (e.g. .wp-block-columns.gds-block-animations-block > .wp-block-column { opacity: 0 }), not to bare .wp-block-columns > .wp-block-column—otherwise above-the-fold blocks lose the animation class but descendants keep opacity 0 with no matching visible rule.

## Gutenberg block markup on the front end
- The main HTML wrapper for a block usually has a class wp-block-{slug} derived from the block name: namespace becomes part of the slug with hyphens.
  Examples: core/paragraph → .wp-block-paragraph, core/heading → .wp-block-heading, core/cover → .wp-block-cover, core/group → .wp-block-group, core/columns → .wp-block-columns, core/column → .wp-block-column, core/media-text → .wp-block-media-text, core/image → .wp-block-image.
  For a block myvendor/my-block → .wp-block-myvendor-my-block (slashes become segment boundaries in the class name).
- The animation class is injected on that block’s outer wrapper element output by render_block (same element that carries wp-block-*).
- Inner blocks sit inside parents (e.g. columns inside columns, headings inside group/cover). To stagger children, descend from the animated parent when it is visible, e.g. .wp-block-columns.gds-animate-visible > .wp-block-column.
- core/query and core/post-template: gds-animate-visible is added only to the enabled block’s outer wrapper (query or post-template), not necessarily .wp-block-query when post-template is the enabled block. Scope “hidden” styles to .gds-block-animations-block on that same wrapper (e.g. .wp-block-post-template.gds-block-animations-block .wp-block-post { opacity: 0 }), not a long ancestor chain without that class—otherwise posts can stay invisible.
- Common extra classes you may see (not exhaustive): alignwide, alignfull, alignleft, alignright, is-layout-constrained, is-layout-flex, is-layout-grid, has-global-padding, wp-block-template-part, is-root-container (often the post content root in patterns).
- Classic / theme wrappers may include .entry-content, .wp-site-blocks, .site-content; prefer wp-block-* on the animated node when possible.

## Safety and quality
- Prefer opacity and transform for motion; keep transitions reasonable.
- Include @media (prefers-reduced-motion: reduce) to reduce or disable motion where appropriate.
- Do not use expression(), behavior:, javascript: URLs in url(), or other unsafe legacy patterns.
GDS_BA_CLAUDE_CONTEXT;

    $context = apply_filters('gds_block_animations_openai_system_context', $context);
    $context = apply_filters('gds_block_animations_gemini_system_context', $context);
    $context = apply_filters('gds_block_animations_claude_system_context', $context);

    return apply_filters('gds_block_animations_ai_system_context', $context);
}

/**
 * Extract Anthropic API error message from decoded JSON body.
 *
 * @param array<string, mixed> $body
 *
 * @return string
 */
function gdsBlockAnimationsClaudeErrorMessageFromBody($body)
{
    if (!is_array($body)) {
        return '';
    }
    if (!empty($body['error']['message']) && is_string($body['error']['message'])) {
        return $body['error']['message'];
    }

    return '';
}

/**
 * Normalize CSS generation mode from the request.
 *
 * @param string $mode
 *
 * @return string append|modify|replace
 */
function gdsBlockAnimationsNormalizeClaudeGenerateMode($mode)
{
    if ($mode === 'append') {
        return 'append';
    }
    if ($mode === 'modify') {
        return 'modify';
    }

    return 'replace';
}

/**
 * Sanitize prior conversation turns for Claude (user + assistant CSS excerpt).
 *
 * @param array<int, mixed> $decoded
 *
 * @return array<int, array{user: string, assistant: string}>
 */
function gdsBlockAnimationsSanitizeClaudeConversation($decoded)
{
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    $slice = array_slice($decoded, -15);

    foreach ($slice as $item) {
        if (!is_array($item)) {
            continue;
        }
        $user = isset($item['user']) ? (string) $item['user'] : '';
        $assistant = isset($item['assistant']) ? (string) $item['assistant'] : '';
        $user = trim($user);
        if ($user === '') {
            continue;
        }
        if (strlen($user) > 4000) {
            $user = substr($user, 0, 4000);
        }
        if (strlen($assistant) > 2500) {
            $assistant = substr($assistant, 0, 2500);
        }
        $out[] = [
            'user' => $user,
            'assistant' => trim($assistant),
        ];
    }

    return $out;
}

/**
 * Call Anthropic Messages API and return generated CSS or WP_Error.
 *
 * @param string $prompt
 * @param string $currentCss
 * @param string $mode replace|append|modify
 * @param array<int, array{user: string, assistant: string}> $conversationHistory prior turns (assistant = excerpt of CSS applied that turn)
 *
 * @return string|WP_Error
 */
function gdsBlockAnimationsClaudeGenerateCss($prompt, $currentCss, $mode, $conversationHistory = [])
{
    $apiKey = gdsBlockAnimationsGetClaudeApiKey();
    if ($apiKey === '') {
        return new WP_Error('gds_ba_claude_key', __('No API key configured.', 'gds-block-animations'));
    }

    $model = gdsBlockAnimationsGetClaudeModel();
    $mode = gdsBlockAnimationsNormalizeClaudeGenerateMode(is_string($mode) ? $mode : 'replace');

    if (!is_string($currentCss)) {
        $currentCss = '';
    }

    if ($mode === 'modify' && trim($currentCss) === '') {
        $mode = 'replace';
    }

    $system = gdsBlockAnimationsGetClaudeSystemContext();

    if ($mode === 'append') {
        $userMessage = sprintf(
            "Existing CSS in the site option:\n---\n%s\n---\n\nUser request:\n%s\n\nOutput ONLY new CSS rules to append. Do not repeat existing rules. Plain CSS only.",
            $currentCss,
            $prompt
        );
    } elseif ($mode === 'modify') {
        $userMessage = sprintf(
            "Current global animation CSS:\n---\n%s\n---\n\nUser request:\n%s\n\nModify this stylesheet to satisfy the request: change only what is needed and preserve everything else (unchanged rules, structure, and comments where they still apply). Output the complete updated CSS only—no markdown, no explanation.",
            $currentCss,
            $prompt
        );
    } else {
        $userMessage = sprintf(
            "Existing CSS (may be fully replaced):\n---\n%s\n---\n\nUser request:\n%s\n\nOutput the complete replacement CSS for the global animation stylesheet. Plain CSS only.",
            $currentCss,
            $prompt
        );
    }

    $messages = [];
    if (is_array($conversationHistory)) {
        foreach ($conversationHistory as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $priorUser = isset($turn['user']) ? trim((string) $turn['user']) : '';
            $priorAssistant = isset($turn['assistant']) ? trim((string) $turn['assistant']) : '';
            if ($priorUser === '') {
                continue;
            }
            $messages[] = [
                'role' => 'user',
                'content' => $priorUser,
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => $priorAssistant !== ''
                    ? $priorAssistant
                    : __('(Prior turn: CSS was updated; see full current stylesheet in the latest message.)', 'gds-block-animations'),
            ];
        }
    }

    $messages[] = [
        'role' => 'user',
        'content' => $userMessage,
    ];

    $payload = [
        'model' => $model,
        'max_tokens' => 8192,
        'temperature' => 0.35,
        'system' => $system,
        'messages' => $messages,
    ];

    $response = wp_remote_post(
        'https://api.anthropic.com/v1/messages',
        [
            'timeout' => 90,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($payload),
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code($response);
    $bodyRaw = wp_remote_retrieve_body($response);
    $body = json_decode($bodyRaw, true);

    if ($status < 200 || $status >= 300 || (is_array($body) && isset($body['type']) && $body['type'] === 'error')) {
        $msg = gdsBlockAnimationsClaudeErrorMessageFromBody(is_array($body) ? $body : []);
        if ($msg === '') {
            $msg = __('Claude API request failed.', 'gds-block-animations');
        }

        if (
            $status === 429
            || stripos($msg, 'rate_limit') !== false
            || stripos($msg, 'Rate limit') !== false
            || stripos($msg, 'credit') !== false
            || stripos($msg, 'billing') !== false
        ) {
            $msg .= ' ' . __(
                'Check usage and billing in the Anthropic Console: https://console.anthropic.com/',
                'gds-block-animations'
            );
        }

        return new WP_Error('gds_ba_claude_http', $msg);
    }

    $replyText = '';
    if (is_array($body) && !empty($body['content']) && is_array($body['content'])) {
        foreach ($body['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (!empty($block['type']) && $block['type'] === 'text' && !empty($block['text']) && is_string($block['text'])) {
                $replyText .= $block['text'];
            }
        }
    }

    $replyText = trim($replyText);
    if ($replyText === '') {
        if (is_array($body)) {
            $errMsg = gdsBlockAnimationsClaudeErrorMessageFromBody($body);
            if ($errMsg !== '') {
                return new WP_Error('gds_ba_claude_api', $errMsg);
            }
        }

        return new WP_Error('gds_ba_claude_response', __('Unexpected API response.', 'gds-block-animations'));
    }

    $css = gdsBlockAnimationsExtractCssFromLlmReply($replyText);

    if ($mode === 'append' && $currentCss !== '') {
        $css = rtrim($currentCss) . "\n\n" . ltrim($css);
    }

    return $css;
}

/**
 * AJAX: generate global animation CSS via Claude (admins only).
 */
function gdsBlockAnimationsAjaxClaudeGenerateCss()
{
    check_ajax_referer('gds_ba_claude', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            ['message' => __('Permission denied.', 'gds-block-animations')],
            403
        );
    }

    $rateKey = 'gds_ba_claude_rl_' . get_current_user_id();
    $count = (int) get_transient($rateKey);
    if ($count >= 8) {
        wp_send_json_error(
            ['message' => __('Too many requests. Try again in a minute.', 'gds-block-animations')],
            429
        );
    }
    set_transient($rateKey, $count + 1, MINUTE_IN_SECONDS);

    $prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
    if (!is_string($prompt) || trim($prompt) === '') {
        wp_send_json_error(
            ['message' => __('Enter a prompt.', 'gds-block-animations')],
            400
        );
    }

    $rawMode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'replace';
    $mode = gdsBlockAnimationsNormalizeClaudeGenerateMode($rawMode);
    $currentCss = isset($_POST['current_css']) ? wp_unslash($_POST['current_css']) : '';
    if (!is_string($currentCss)) {
        $currentCss = '';
    }

    $conversation = [];
    if (!empty($_POST['conversation'])) {
        $rawConv = wp_unslash($_POST['conversation']);
        if (is_string($rawConv) && $rawConv !== '') {
            $decoded = json_decode($rawConv, true);
            if (is_array($decoded)) {
                $conversation = gdsBlockAnimationsSanitizeClaudeConversation($decoded);
            }
        }
    }

    if (gdsBlockAnimationsGetClaudeApiKey() === '') {
        wp_send_json_error(
            [
                'message' => __(
                    'Add an API key below or define GDS_BLOCK_ANIMATIONS_CLAUDE_KEY in wp-config.php.',
                    'gds-block-animations'
                ),
            ],
            400
        );
    }

    $result = gdsBlockAnimationsClaudeGenerateCss($prompt, $currentCss, $mode, $conversation);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    }

    $sanitized = gdsBlockAnimationsSanitizeGlobalCss($result);
    wp_send_json_success(['css' => $sanitized]);
}
add_action('wp_ajax_gds_ba_claude_generate_css', 'gdsBlockAnimationsAjaxClaudeGenerateCss');

/**
 * AJAX: generate global animation CSS via Claude and persist to the option in one request (frontend bar).
 */
function gdsBlockAnimationsAjaxClaudeGenerateAndSaveGlobalCss()
{
    check_ajax_referer('gds_ba_claude', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            ['message' => __('Permission denied.', 'gds-block-animations')],
            403
        );
    }

    $rateKey = 'gds_ba_claude_rl_' . get_current_user_id();
    $count = (int) get_transient($rateKey);
    if ($count >= 8) {
        wp_send_json_error(
            ['message' => __('Too many requests. Try again in a minute.', 'gds-block-animations')],
            429
        );
    }
    set_transient($rateKey, $count + 1, MINUTE_IN_SECONDS);

    $saveKey = 'gds_ba_claude_save_rl_' . get_current_user_id();
    $saveCount = (int) get_transient($saveKey);
    if ($saveCount >= 30) {
        wp_send_json_error(
            ['message' => __('Too many save requests. Try again shortly.', 'gds-block-animations')],
            429
        );
    }

    $prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
    if (!is_string($prompt) || trim($prompt) === '') {
        wp_send_json_error(
            ['message' => __('Enter a prompt.', 'gds-block-animations')],
            400
        );
    }

    $rawMode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'replace';
    $mode = gdsBlockAnimationsNormalizeClaudeGenerateMode($rawMode);
    $currentCss = isset($_POST['current_css']) ? wp_unslash($_POST['current_css']) : '';
    if (!is_string($currentCss)) {
        $currentCss = '';
    }

    $conversation = [];
    if (!empty($_POST['conversation'])) {
        $rawConv = wp_unslash($_POST['conversation']);
        if (is_string($rawConv) && $rawConv !== '') {
            $decoded = json_decode($rawConv, true);
            if (is_array($decoded)) {
                $conversation = gdsBlockAnimationsSanitizeClaudeConversation($decoded);
            }
        }
    }

    if (gdsBlockAnimationsGetClaudeApiKey() === '') {
        wp_send_json_error(
            [
                'message' => __(
                    'Add an API key below or define GDS_BLOCK_ANIMATIONS_CLAUDE_KEY in wp-config.php.',
                    'gds-block-animations'
                ),
            ],
            400
        );
    }

    $result = gdsBlockAnimationsClaudeGenerateCss($prompt, $currentCss, $mode, $conversation);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    }

    $sanitized = gdsBlockAnimationsSanitizeGlobalCss($result);
    update_option(gdsBlockAnimationsGetGlobalCssOptionName(), $sanitized, false);
    set_transient($saveKey, $saveCount + 1, MINUTE_IN_SECONDS);

    wp_send_json_success(['css' => $sanitized]);
}
add_action('wp_ajax_gds_ba_claude_generate_and_save_global_css', 'gdsBlockAnimationsAjaxClaudeGenerateAndSaveGlobalCss');

/**
 * AJAX: save global animation CSS from the frontend Claude bar (admins only).
 */
function gdsBlockAnimationsAjaxClaudeSaveGlobalCss()
{
    check_ajax_referer('gds_ba_claude_save_css', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            ['message' => __('Permission denied.', 'gds-block-animations')],
            403
        );
    }

    $rateKey = 'gds_ba_claude_save_rl_' . get_current_user_id();
    $count = (int) get_transient($rateKey);
    if ($count >= 30) {
        wp_send_json_error(
            ['message' => __('Too many save requests. Try again shortly.', 'gds-block-animations')],
            429
        );
    }
    set_transient($rateKey, $count + 1, MINUTE_IN_SECONDS);

    $css = isset($_POST['css']) ? wp_unslash($_POST['css']) : '';
    if (!is_string($css)) {
        $css = '';
    }

    $sanitized = gdsBlockAnimationsSanitizeGlobalCss($css);
    update_option(gdsBlockAnimationsGetGlobalCssOptionName(), $sanitized, false);

    wp_send_json_success(['css' => $sanitized]);
}
add_action('wp_ajax_gds_ba_claude_save_global_css', 'gdsBlockAnimationsAjaxClaudeSaveGlobalCss');

/**
 * Enqueue settings page script (Claude CSS generation helper).
 *
 * @param string $hookSuffix
 */
function gdsBlockAnimationsAdminEnqueueSettings($hookSuffix)
{
    if ($hookSuffix !== 'settings_page_gds-block-animations') {
        return;
    }

    wp_enqueue_script(
        'gds-block-animations-admin-settings',
        plugin_dir_url(__FILE__) . 'assets/admin-settings.js',
        [],
        '1.0.0',
        true
    );

    wp_localize_script('gds-block-animations-admin-settings', 'gdsBlockAnimationsAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gds_ba_claude'),
        'strings' => [
            'emptyPrompt' => __('Enter a prompt first.', 'gds-block-animations'),
            'working' => __('Generating…', 'gds-block-animations'),
            'done' => __('CSS inserted into the field above. Review and click Save settings.', 'gds-block-animations'),
            'failed' => __('Could not generate CSS.', 'gds-block-animations'),
            'network' => __('Network error.', 'gds-block-animations'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'gdsBlockAnimationsAdminEnqueueSettings');

/**
 * Sanitize custom CSS before save/output (settings / inline).
 *
 * @param mixed $css
 *
 * @return string
 */
function gdsBlockAnimationsSanitizeGlobalCss($css)
{
    if (!is_string($css)) {
        return '';
    }

    $css = str_replace("\0", '', $css);
    $maxLength = 500000;
    if (strlen($css) > $maxLength) {
        $css = substr($css, 0, $maxLength);
    }

    // Break out of an enclosing </style> if pasted maliciously.
    $css = (string) preg_replace('/<\/style\b/i', '/**/', $css);

    return $css;
}

/**
 * Enqueue site-wide custom animation CSS on every frontend page (from DB).
 */
function gdsBlockAnimationsEnqueueGlobalCss()
{
    if (is_admin()) {
        return;
    }

    $css = get_option(gdsBlockAnimationsGetGlobalCssOptionName(), '');
    if (!is_string($css) || $css === '') {
        return;
    }

    $sanitized = gdsBlockAnimationsSanitizeGlobalCss($css);
    if ($sanitized === '') {
        return;
    }

    wp_register_style('gds-block-animations-global', false, [], null);
    wp_enqueue_style('gds-block-animations-global');
    wp_add_inline_style('gds-block-animations-global', $sanitized);
}
add_action('wp_enqueue_scripts', 'gdsBlockAnimationsEnqueueGlobalCss', 100);

/**
 * Resolve a stable dashicon class for a block.
 *
 * @param string $blockName
 * @param mixed  $icon
 *
 * @return string
 */
function gdsBlockAnimationsGetDashiconClass($blockName, $icon)
{
    $dashiconByBlock = [
        'core/heading' => 'dashicons-heading',
        'core/paragraph' => 'dashicons-editor-paragraph',
        'core/list' => 'dashicons-editor-ul',
        'core/list-item' => 'dashicons-editor-ul',
        'core/quote' => 'dashicons-format-quote',
        'core/pullquote' => 'dashicons-format-quote',
        'core/cover' => 'dashicons-cover-image',
        'core/image' => 'dashicons-format-image',
        'core/gallery' => 'dashicons-format-gallery',
        'core/video' => 'dashicons-format-video',
        'core/audio' => 'dashicons-format-audio',
        'core/media-text' => 'dashicons-media-text',
        'core/table' => 'dashicons-table-col-after',
        'core/button' => 'dashicons-button',
        'core/buttons' => 'dashicons-button',
        'core/columns' => 'dashicons-columns',
        'core/column' => 'dashicons-columns',
        'core/group' => 'dashicons-screenoptions',
        'core/accordion' => 'dashicons-menu-alt3',
        'core/accordion-item' => 'dashicons-menu-alt3',
        'core/accordion-heading' => 'dashicons-menu-alt3',
        'core/accordion-panel' => 'dashicons-menu-alt3',
    ];

    if (isset($dashiconByBlock[$blockName])) {
        return $dashiconByBlock[$blockName];
    }

    if (is_string($icon) && strpos($icon, 'dashicons-') === 0) {
        return $icon;
    }

    return 'dashicons-block-default';
}

/**
 * Enqueue plugin styles and scripts on frontend only.
 */
function gdsBlockAnimationsEnqueueAssets()
{
    if (is_admin()) {
        return;
    }

    wp_enqueue_style(
        'gds-block-animations-style',
        plugin_dir_url(__FILE__) . 'assets/style.css',
        [],
        '1.0.2'
    );

    wp_enqueue_script(
        'gds-block-animations-script',
        plugin_dir_url(__FILE__) . 'assets/script.js',
        [],
        '1.0.6',
        true
    );

    wp_localize_script('gds-block-animations-script', 'gdsBlockAnimationsConfig', [
        'skipAboveFold' => gdsBlockAnimationsGetSkipAboveFold(),
    ]);
}
add_action('wp_enqueue_scripts', 'gdsBlockAnimationsEnqueueAssets');

/**
 * Floating Claude prompt bar on the public site (admins only).
 */
function gdsBlockAnimationsEnqueueFrontClaudeBar()
{
    if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options')) {
        return;
    }

    wp_enqueue_style(
        'gds-block-animations-front-claude',
        plugin_dir_url(__FILE__) . 'assets/front-claude-bar.css',
        [],
        '1.0.3'
    );

    wp_enqueue_script(
        'gds-block-animations-front-claude',
        plugin_dir_url(__FILE__) . 'assets/front-claude-bar.js',
        [],
        '1.0.3',
        true
    );

    $savedCss = get_option(gdsBlockAnimationsGetGlobalCssOptionName(), '');
    if (!is_string($savedCss)) {
        $savedCss = '';
    }

    wp_localize_script('gds-block-animations-front-claude', 'gdsBlockAnimationsFrontClaude', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonceGenerate' => wp_create_nonce('gds_ba_claude'),
        'savedGlobalCss' => gdsBlockAnimationsSanitizeGlobalCss($savedCss),
        'settingsUrl' => admin_url('options-general.php?page=gds-block-animations'),
        'strings' => [
            'toggleLabel' => __('Animation CSS assistant', 'gds-block-animations'),
            'panelTitle' => __('Claude: global animation CSS', 'gds-block-animations'),
            'contextHint' => __('Each run sends your saved CSS, this prompt, and the recent conversation below to Claude, then writes the result live. Refine with short follow-up prompts.', 'gds-block-animations'),
            'transcriptLabel' => __('Conversation', 'gds-block-animations'),
            'transcriptEmpty' => __(
                'No turns yet. Each time you generate and apply, a new block appears here: your prompt, then the CSS excerpt Claude produced.',
                'gds-block-animations'
            ),
            'exchangeHeading' => __('Round', 'gds-block-animations'),
            'emptyPrompt' => __('Enter a prompt first.', 'gds-block-animations'),
            'working' => __('Generating and applying…', 'gds-block-animations'),
            'failed' => __('Could not generate or save CSS.', 'gds-block-animations'),
            'network' => __('Network error.', 'gds-block-animations'),
            'modeFieldLabel' => __('Generation mode', 'gds-block-animations'),
            'modeModify' => __('Modify existing CSS (surgical edits)', 'gds-block-animations'),
            'modeReplace' => __('Replace entire global CSS', 'gds-block-animations'),
            'modeAppend' => __('Append to current CSS', 'gds-block-animations'),
            'promptLabel' => __('Prompt', 'gds-block-animations'),
            'generateApply' => __('Generate and apply', 'gds-block-animations'),
            'generateApplyMeta' => __('Sends to Claude and saves global CSS for visitors. Reload the page if styles look stale.', 'gds-block-animations'),
            'resultLabel' => __('Current global CSS (editable)', 'gds-block-animations'),
            'saved' => __('Saved. Reload if styles do not update.', 'gds-block-animations'),
            'openSettings' => __('Plugin settings', 'gds-block-animations'),
            'statusIdle' => __('Enter a prompt, then generate and apply. Prior prompts in this browser session stay in the conversation.', 'gds-block-animations'),
            'turnYou' => __('You', 'gds-block-animations'),
            'turnApplied' => __('Applied CSS (excerpt)', 'gds-block-animations'),
            'clearConversation' => __('Clear conversation', 'gds-block-animations'),
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'gdsBlockAnimationsEnqueueFrontClaudeBar', 200);

/**
 * Enqueue block editor assets.
 */
function gdsBlockAnimationsEnqueueEditorAssets()
{
    $enabledBlocks = gdsBlockAnimationsGetEnabledBlocks();

    wp_enqueue_script(
        'gds-block-animations-editor',
        plugin_dir_url(__FILE__) . 'assets/editor.js',
        ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-hooks', 'wp-compose', 'wp-i18n'],
        '1.0.0',
        true
    );

    wp_localize_script('gds-block-animations-editor', 'gdsBlockAnimationsEditor', [
        'enabledBlocks' => array_keys(array_filter($enabledBlocks)),
    ]);
}
add_action('enqueue_block_editor_assets', 'gdsBlockAnimationsEnqueueEditorAssets');

/**
 * Add animation classes to enabled blocks during render.
 *
 * @param string $blockContent
 * @param array  $block
 *
 * @return string
 */
function gdsBlockAnimationsAddBlockClass($blockContent, $block)
{
    if (is_admin()) {
        return $blockContent;
    }

    $enabledBlocks = gdsBlockAnimationsGetEnabledBlocks();

    if (isset($enabledBlocks[$block['blockName']]) && $enabledBlocks[$block['blockName']]) {
        $isDisabled = (
            (isset($block['attrs']['gdsBlockAnimationsDisabled']) && $block['attrs']['gdsBlockAnimationsDisabled']) ||
            (isset($block['attrs']['gdsAnimateDisabled']) && $block['attrs']['gdsAnimateDisabled'])
        );

        if ($isDisabled) {
            return $blockContent;
        }

        $classInjection = 'gds-block-animations-block';

        if (preg_match('/<[a-z0-9\-]+[^>]*\sclass="[^"]*"/i', $blockContent)) {
            $blockContent = preg_replace(
                '/(<[a-z0-9\-]+[^>]*\sclass=")([^"]*)/i',
                '$1$2 ' . $classInjection,
                $blockContent,
                1
            );
        } else {
            $blockContent = preg_replace(
                '/(<[a-z0-9\-]+)([^>]*>)/i',
                '$1 class="' . $classInjection . '"$2',
                $blockContent,
                1
            );
        }
    }

    return $blockContent;
}
add_filter('render_block', 'gdsBlockAnimationsAddBlockClass', 10, 2);

/**
 * Add settings page to WordPress admin menu.
 */
function gdsBlockAnimationsAddAdminMenu()
{
    add_options_page(
        'GDS Block Animations Settings',
        'GDS Block Animations',
        'manage_options',
        'gds-block-animations',
        'gdsBlockAnimationsSettingsPage'
    );
}
add_action('admin_menu', 'gdsBlockAnimationsAddAdminMenu');

/**
 * Register plugin settings.
 */
function gdsBlockAnimationsRegisterSettings()
{
    register_setting('gds_block_animations_options', 'gds_block_animations_blocks');
    register_setting('gds_block_animations_options', gdsBlockAnimationsGetGlobalCssOptionName(), [
        'type' => 'string',
        'sanitize_callback' => 'gdsBlockAnimationsSanitizeGlobalCss',
        'default' => '',
    ]);
    register_setting('gds_block_animations_options', gdsBlockAnimationsGetSkipAboveFoldOptionName(), [
        'type' => 'boolean',
        'sanitize_callback' => function ($value) {
            return $value === true || $value === 1 || $value === '1' || $value === 'on';
        },
        'default' => false,
    ]);
    register_setting('gds_block_animations_options', 'gds_block_animations_claude_api_key', [
        'type' => 'string',
        'sanitize_callback' => function ($value) {
            if (!is_string($value)) {
                return (string) get_option('gds_block_animations_claude_api_key', '');
            }
            $value = trim($value);
            if ($value === '') {
                return (string) get_option('gds_block_animations_claude_api_key', '');
            }

            return $value;
        },
        'default' => '',
    ]);
    register_setting('gds_block_animations_options', 'gds_block_animations_claude_model', [
        'type' => 'string',
        'sanitize_callback' => function ($value) {
            $defaultModel = 'claude-sonnet-4-20250514';
            if (!is_string($value)) {
                return $defaultModel;
            }
            $value = sanitize_text_field(trim($value));
            if ($value === '') {
                return $defaultModel;
            }
            $value = preg_replace('/[^a-zA-Z0-9_.-]/', '', $value);

            return $value !== '' ? $value : $defaultModel;
        },
        'default' => 'claude-sonnet-4-20250514',
    ]);
}
add_action('admin_init', 'gdsBlockAnimationsRegisterSettings');

/**
 * Render settings page.
 */
function gdsBlockAnimationsSettingsPage()
{
    wp_enqueue_style('dashicons');

    $enabledBlocks = gdsBlockAnimationsGetEnabledBlocks();
    $usedBlockNamesOnPages = gdsBlockAnimationsGetUsedBlockNamesOnPages();

    $registry = WP_Block_Type_Registry::get_instance();
    $allBlocks = $registry->get_all_registered();

    $categoryLabels = [
        'text' => 'Text',
        'media' => 'Media',
        'design' => 'Design',
        'widgets' => 'Widgets',
        'theme' => 'Theme',
        'embed' => 'Embeds',
        'unused' => 'Unused on pages',
    ];

    $groupedBlocks = [];
    foreach ($allBlocks as $blockName => $blockType) {
        $isUsedOnPages = isset($usedBlockNamesOnPages[$blockName]);
        $category = $isUsedOnPages ? ($blockType->category ?? 'other') : 'unused';

        if (!isset($groupedBlocks[$category])) {
            $groupedBlocks[$category] = [];
        }

        $groupedBlocks[$category][] = [
            'name' => $blockName,
            'title' => $blockType->title ?? ucwords(str_replace(['-', '/'], ' ', $blockName)),
            'namespace' => explode('/', $blockName)[0],
            'isUsedOnPages' => $isUsedOnPages,
            'iconClass' => gdsBlockAnimationsGetDashiconClass($blockName, $blockType->icon ?? ''),
        ];
    }

    uksort($groupedBlocks, function ($a, $b) use ($categoryLabels) {
        if ($a === 'unused') {
            return 1;
        }

        if ($b === 'unused') {
            return -1;
        }

        $order = array_keys($categoryLabels);
        $posA = array_search($a, $order, true);
        $posB = array_search($b, $order, true);

        if ($posA !== false && $posB !== false) {
            return $posA - $posB;
        }

        if ($posA !== false) {
            return -1;
        }

        if ($posB !== false) {
            return 1;
        }

        return strcmp($a, $b);
    });
    ?>
    <div class="wrap">
        <style>
            .gds-ba-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(240px, 1fr));
                gap: 8px 12px;
            }

            .gds-ba-item {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 0;
                padding: 8px 10px;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                background: #fff;
            }

            .gds-ba-item .dashicons {
                color: #1d2327;
            }

            .gds-ba-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 20px;
                height: 20px;
                color: #1d2327;
            }

            .gds-ba-icon svg {
                width: 20px;
                height: 20px;
                fill: currentColor;
            }

            .gds-ba-item code {
                margin-left: auto;
                color: #646970;
                font-size: 11px;
            }

            .gds-ba-item.is-unused {
                background: #f6f7f7;
                color: #646970;
            }

            .gds-ba-item.is-unused .dashicons {
                color: #8c8f94;
            }

            @media (max-width: 960px) {
                .gds-ba-grid {
                    grid-template-columns: 1fr;
                }
            }

            .gds-ba-sticky-save {
                display: none;
                position: fixed;
                z-index: 100000;
                right: 0;
                bottom: 0;
                left: 0;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 12px;
                padding: 12px 20px;
                background: #fff;
                border-top: 1px solid #c3c4c7;
                box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.08);
            }

            .gds-ba-sticky-save.is-visible {
                display: flex;
            }

            @media screen and (min-width: 783px) {
                body:not(.folded) .gds-ba-sticky-save {
                    left: 160px;
                }

                body.folded .gds-ba-sticky-save {
                    left: 36px;
                }
            }

            .gds-ba-sticky-save-msg {
                font-size: 13px;
                color: #50575e;
            }
        </style>
        <h1>GDS Block Animations Settings</h1>
        <p>Select which blocks should be animated on scroll. Style the animations in your theme CSS.</p>
        <p><em>Blocks not used on any published page are grouped under "Unused on pages" and cannot be enabled.</em></p>

        <?php
        $globalCss = get_option(gdsBlockAnimationsGetGlobalCssOptionName(), '');
        if (!is_string($globalCss)) {
            $globalCss = '';
        }
        $gdsBaClaudeDefaultMode = trim($globalCss) !== '' ? 'modify' : 'replace';
        ?>

        <form id="gds-ba-settings-form" method="post" action="options.php">
            <?php settings_fields('gds_block_animations_options'); ?>

            <h2 class="title"><?php esc_html_e('Animation behavior', 'gds-block-animations'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Above the fold', 'gds-block-animations'); ?></th>
                    <td>
                        <input type="hidden" name="<?php echo esc_attr(gdsBlockAnimationsGetSkipAboveFoldOptionName()); ?>" value="0">
                        <label for="gds_block_animations_skip_above_fold">
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(gdsBlockAnimationsGetSkipAboveFoldOptionName()); ?>"
                                id="gds_block_animations_skip_above_fold"
                                value="1"
                                <?php checked(gdsBlockAnimationsGetSkipAboveFold()); ?>
                            >
                            <?php esc_html_e('Do not apply scroll animation to blocks visible without scrolling (initial viewport)', 'gds-block-animations'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, the animation class is removed in the browser for any block that intersects the first screen, and the visible classes are applied immediately so content does not stay hidden. Prefer CSS that hides children only while the parent has .gds-block-animations-block (e.g. .wp-block-columns.gds-block-animations-block > .wp-block-column), not bare .wp-block-columns > .wp-block-column.', 'gds-block-animations'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2 class="title"><?php esc_html_e('Anthropic Claude (CSS generation)', 'gds-block-animations'); ?></h2>
            <p class="description">
                <?php esc_html_e('API key is stored in the database and used only from this server to call the Anthropic Messages API. You can set GDS_BLOCK_ANIMATIONS_CLAUDE_KEY in wp-config.php instead—then the key field is optional.', 'gds-block-animations'); ?>
            </p>
            <p class="description">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: Anthropic console URL */
                        __(
                            'If generation fails with rate limits or account errors, check your key and usage in the <a href="%s" target="_blank" rel="noopener noreferrer">Anthropic Console</a>.',
                            'gds-block-animations'
                        ),
                        'https://console.anthropic.com/'
                    ),
                    [
                        'a' => [
                            'href' => true,
                            'target' => true,
                            'rel' => true,
                        ],
                    ]
                );
                ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="gds_block_animations_claude_api_key"><?php esc_html_e('API key', 'gds-block-animations'); ?></label>
                    </th>
                    <td>
                        <?php
                        $hasKeyConstant = false;
                        if (defined('GDS_BLOCK_ANIMATIONS_CLAUDE_KEY')) {
                            $kConst = constant('GDS_BLOCK_ANIMATIONS_CLAUDE_KEY');
                            $hasKeyConstant = is_string($kConst) && $kConst !== '';
                        }
                        ?>
                        <?php
                        if (!$hasKeyConstant && gdsBlockAnimationsGetClaudeApiKey() !== '') {
                            echo '<p class="description" style="color:#00a32a;"><strong>' . esc_html__(
                                'API key is saved. Leave the field empty to keep it, or paste a new key to replace.',
                                'gds-block-animations',
                            ) . '</strong></p>';
                        }
                        ?>
                        <input
                            name="gds_block_animations_claude_api_key"
                            type="text"
                            id="gds_block_animations_claude_api_key"
                            class="regular-text code"
                            autocomplete="off"
                            spellcheck="false"
                            <?php disabled($hasKeyConstant); ?>
                            placeholder="<?php echo $hasKeyConstant ? esc_attr__('Using wp-config constant', 'gds-block-animations') : esc_attr__('sk-ant-…', 'gds-block-animations'); ?>"
                        >
                        <?php if ($hasKeyConstant) : ?>
                            <p class="description"><?php esc_html_e('Key is defined in wp-config.php. Leave this field empty.', 'gds-block-animations'); ?></p>
                        <?php endif; ?>
                        <?php if (!$hasKeyConstant && gdsBlockAnimationsGetClaudeApiKey() !== '') : ?>
                            <label style="display: inline-block; margin-top: 8px;">
                                <input type="checkbox" name="gds_block_animations_clear_claude_key" value="1" id="gds_block_animations_clear_claude_key">
                                <?php esc_html_e('Clear stored API key on save', 'gds-block-animations'); ?>
                            </label>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gds_block_animations_claude_model"><?php esc_html_e('Model', 'gds-block-animations'); ?></label>
                    </th>
                    <td>
                        <input
                            name="gds_block_animations_claude_model"
                            type="text"
                            id="gds_block_animations_claude_model"
                            value="<?php echo esc_attr(gdsBlockAnimationsGetClaudeModel()); ?>"
                            class="regular-text"
                        >
                        <p class="description"><?php esc_html_e('Example: claude-sonnet-4-20250514, claude-3-5-haiku-20241022', 'gds-block-animations'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="title"><?php esc_html_e('Global animation CSS', 'gds-block-animations'); ?></h2>
            <p class="description">
                <?php esc_html_e('Stored in the database and output as one inline stylesheet on every page. Use classes such as .gds-block-animations-block and .gds-animate-visible (or .gds-block-animations-visible) in your selectors.', 'gds-block-animations'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="gds_block_animations_global_css"><?php esc_html_e('Custom CSS', 'gds-block-animations'); ?></label>
                    </th>
                    <td>
                        <textarea
                            name="<?php echo esc_attr(gdsBlockAnimationsGetGlobalCssOptionName()); ?>"
                            id="gds_block_animations_global_css"
                            class="large-text code"
                            rows="14"
                            spellcheck="false"
                        ><?php echo esc_textarea($globalCss); ?></textarea>
                        <fieldset style="margin-top: 1rem; padding: 12px; border: 1px solid #c3c4c7; border-radius: 4px; background: #f6f7f7;">
                            <legend style="font-weight: 600; padding: 0 6px;">
                                <?php esc_html_e('Generate with Claude', 'gds-block-animations'); ?>
                            </legend>
                            <p class="description" style="margin-top: 0;">
                                <?php esc_html_e('Only updates the custom CSS field above until you save. Describe the motion you want for blocks (e.g. fade-up for covers, staggered columns).', 'gds-block-animations'); ?>
                            </p>
                            <p class="description">
                                <?php esc_html_e('Claude always receives whatever is currently in the Custom CSS box when you click Generate—including text you added but have not saved yet—so you can iterate and ask for changes to the latest draft.', 'gds-block-animations'); ?>
                            </p>
                            <p>
                                <label for="gds-ba-claude-mode" class="screen-reader-text"><?php esc_html_e('How to apply', 'gds-block-animations'); ?></label>
                                <select id="gds-ba-claude-mode" style="max-width: 100%;">
                                    <option value="modify" <?php selected($gdsBaClaudeDefaultMode, 'modify'); ?>><?php esc_html_e('Modify existing CSS (surgical edits)', 'gds-block-animations'); ?></option>
                                    <option value="replace" <?php selected($gdsBaClaudeDefaultMode, 'replace'); ?>><?php esc_html_e('Replace entire custom CSS', 'gds-block-animations'); ?></option>
                                    <option value="append" <?php selected($gdsBaClaudeDefaultMode, 'append'); ?>><?php esc_html_e('Append to current custom CSS', 'gds-block-animations'); ?></option>
                                </select>
                            </p>
                            <p>
                                <label for="gds-ba-claude-prompt"><strong><?php esc_html_e('Prompt', 'gds-block-animations'); ?></strong></label><br>
                                <textarea
                                    id="gds-ba-claude-prompt"
                                    class="large-text"
                                    rows="4"
                                    spellcheck="true"
                                    placeholder="<?php esc_attr_e('Example: Subtle fade-up for .wp-block-cover and typography blocks; respect reduced motion.', 'gds-block-animations'); ?>"
                                ></textarea>
                            </p>
                            <p style="margin-bottom: 0;">
                                <button type="button" class="button button-secondary" id="gds-ba-claude-generate">
                                    <?php esc_html_e('Generate CSS', 'gds-block-animations'); ?>
                                </button>
                                <span id="gds-ba-claude-status" class="description" style="margin-left: 8px; vertical-align: middle;" aria-live="polite"></span>
                            </p>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <h2 class="title"><?php esc_html_e('Animated blocks', 'gds-block-animations'); ?></h2>
            <table class="form-table">
                <?php foreach ($groupedBlocks as $category => $blocks) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($categoryLabels[$category] ?? ucfirst($category)); ?></th>
                        <td>
                            <fieldset>
                                <div class="gds-ba-grid">
                                <?php
                                usort($blocks, function ($a, $b) {
                                    return strcmp($a['title'], $b['title']);
                                });

                                foreach ($blocks as $block) :
                                    $isEnabled = isset($enabledBlocks[$block['name']]) && $enabledBlocks[$block['name']];
                                    $isUnusedBlock = !$block['isUsedOnPages'];
                                ?>
                                    <label class="gds-ba-item <?php echo $isUnusedBlock ? 'is-unused' : ''; ?>">
                                        <input
                                            type="checkbox"
                                            name="gds_block_animations_blocks[<?php echo esc_attr($block['name']); ?>]"
                                            value="1"
                                            <?php checked($isEnabled); ?>
                                            <?php disabled($isUnusedBlock); ?>
                                        >
                                        <span class="gds-ba-icon" aria-hidden="true">
                                            <span class="dashicons <?php echo esc_attr($block['iconClass']); ?>"></span>
                                        </span>
                                        <?php echo esc_html($block['title']); ?>
                                        <code><?php echo esc_html($block['name']); ?></code>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                            </fieldset>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <p class="submit" id="gds-ba-primary-submit-anchor">
                <?php
                submit_button(
                    __('Save settings', 'gds-block-animations'),
                    'primary',
                    'submit',
                    false,
                    [
                        'id' => 'gds-ba-settings-submit',
                    ]
                );
                ?>
            </p>
        </form>

        <div
            id="gds-ba-sticky-save"
            class="gds-ba-sticky-save"
            aria-hidden="true"
        >
            <p class="gds-ba-sticky-save-msg"><?php esc_html_e('You have unsaved changes.', 'gds-block-animations'); ?></p>
            <button type="button" class="button button-primary button-large" id="gds-ba-sticky-submit">
                <?php esc_html_e('Save settings', 'gds-block-animations'); ?>
            </button>
        </div>

        <script>
            (function () {
                var form = document.getElementById('gds-ba-settings-form');
                var anchor = document.getElementById('gds-ba-primary-submit-anchor');
                var bar = document.getElementById('gds-ba-sticky-save');
                var stickyBtn = document.getElementById('gds-ba-sticky-submit');
                var realSubmit = document.getElementById('gds-ba-settings-submit');

                if (!form || !anchor || !bar || !stickyBtn || !realSubmit) {
                    return;
                }

                var dirty = false;
                var anchorInView = true;

                function setStickyVisible(visible) {
                    bar.classList.toggle('is-visible', visible);
                    bar.setAttribute('aria-hidden', visible ? 'false' : 'true');
                }

                function updateSticky() {
                    setStickyVisible(dirty && !anchorInView);
                }

                form.addEventListener('input', function () {
                    dirty = true;
                    updateSticky();
                });

                form.addEventListener('change', function () {
                    dirty = true;
                    updateSticky();
                });

                var io = new IntersectionObserver(
                    function (entries) {
                        if (!entries.length) {
                            return;
                        }
                        anchorInView = entries[0].isIntersecting;
                        updateSticky();
                    },
                    {
                        threshold: 0,
                        rootMargin: '0px 0px 0px 0px',
                    }
                );

                io.observe(anchor);

                stickyBtn.addEventListener('click', function () {
                    realSubmit.click();
                });
            })();
        </script>
    </div>
    <?php
}
