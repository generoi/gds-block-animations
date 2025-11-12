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
        '1.0.0'
    );

    wp_enqueue_script(
        'gds-block-animations-script',
        plugin_dir_url(__FILE__) . 'assets/script.js',
        [],
        '1.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'gdsBlockAnimationsEnqueueAssets');

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
}
add_action('admin_init', 'gdsBlockAnimationsRegisterSettings');

/**
 * Render settings page.
 */
function gdsBlockAnimationsSettingsPage()
{
    $enabledBlocks = gdsBlockAnimationsGetEnabledBlocks();

    $registry = WP_Block_Type_Registry::get_instance();
    $allBlocks = $registry->get_all_registered();

    $categoryLabels = [
        'text' => 'Text',
        'media' => 'Media',
        'design' => 'Design',
        'widgets' => 'Widgets',
        'theme' => 'Theme',
        'embed' => 'Embeds',
    ];

    $groupedBlocks = [];
    foreach ($allBlocks as $blockName => $blockType) {
        $category = $blockType->category ?? 'other';

        if (!isset($groupedBlocks[$category])) {
            $groupedBlocks[$category] = [];
        }

        $groupedBlocks[$category][] = [
            'name' => $blockName,
            'title' => $blockType->title ?? ucwords(str_replace(['-', '/'], ' ', $blockName)),
            'namespace' => explode('/', $blockName)[0],
        ];
    }

    uksort($groupedBlocks, function ($a, $b) use ($categoryLabels) {
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
        <h1>GDS Block Animations Settings</h1>
        <p>Select which blocks should be animated on scroll. Style the animations in your theme CSS.</p>

        <form method="post" action="options.php">
            <?php settings_fields('gds_block_animations_options'); ?>

            <table class="form-table">
                <?php foreach ($groupedBlocks as $category => $blocks) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($categoryLabels[$category] ?? ucfirst($category)); ?></th>
                        <td>
                            <fieldset>
                                <?php
                                usort($blocks, function ($a, $b) {
                                    return strcmp($a['title'], $b['title']);
                                });

                                foreach ($blocks as $block) :
                                    $isEnabled = isset($enabledBlocks[$block['name']]) && $enabledBlocks[$block['name']];
                                ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input
                                            type="checkbox"
                                            name="gds_block_animations_blocks[<?php echo esc_attr($block['name']); ?>]"
                                            value="1"
                                            <?php checked($isEnabled); ?>
                                        >
                                        <?php echo esc_html($block['title']); ?>
                                        <code style="margin-left: 8px; color: #666; font-size: 11px;"><?php echo esc_html($block['name']); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
