/**
 * GDS Block Animations - Block Editor Controls
 * Adds "Disable Animation" checkbox to enabled blocks
 */

(function (wp) {
  const {__} = wp.i18n;
  const {addFilter} = wp.hooks;
  const {Fragment} = wp.element;
  const {InspectorControls} = wp.blockEditor;
  const {createHigherOrderComponent} = wp.compose;
  const {PanelBody, ToggleControl} = wp.components;

  // Get enabled blocks from PHP (passed via wp_localize_script)
  const ENABLED_BLOCKS =
    window.gdsBlockAnimationsEditor?.enabledBlocks ||
    window.gdsAnimateEditor?.enabledBlocks ||
    [];

  /**
   * Add custom attribute to enabled blocks
   */
  function addAttributes(settings, name) {
    if (ENABLED_BLOCKS.includes(name)) {
      settings.attributes = {
        ...settings.attributes,
        gdsAnimateDisabled: {
          type: 'boolean',
          default: false,
        },
      };
    }
    return settings;
  }

  /**
   * Add inspector controls to enabled blocks
   */
  const withInspectorControls = createHigherOrderComponent((BlockEdit) => {
    return (props) => {
      const {attributes, setAttributes, name} = props;

      // Only add controls to enabled blocks
      if (!ENABLED_BLOCKS.includes(name)) {
        return wp.element.createElement(BlockEdit, props);
      }

      const {gdsAnimateDisabled = false} = attributes;

      return wp.element.createElement(
        Fragment,
        null,
        wp.element.createElement(BlockEdit, props),
        wp.element.createElement(
          InspectorControls,
          null,
          wp.element.createElement(
            PanelBody,
            {
              title: __('Animation Settings', 'gds-block-animations'),
              initialOpen: false,
            },
            wp.element.createElement(ToggleControl, {
              label: __('Disable scroll animation', 'gds-block-animations'),
              checked: gdsAnimateDisabled,
              onChange: () =>
                setAttributes({gdsAnimateDisabled: !gdsAnimateDisabled}),
              help: __(
                'Turn off the scroll-based animation for this block',
                'gds-block-animations',
              ),
            }),
          ),
        ),
      );
    };
  }, 'withInspectorControls');

  /**
   * Add custom props to save element
   */
  function addSaveProps(extraProps, blockType, attributes) {
    if (ENABLED_BLOCKS.includes(blockType.name)) {
      if (attributes.gdsAnimateDisabled) {
        extraProps['data-gds-animate-disabled'] = 'true';
      }
    }
    return extraProps;
  }

  // Register filters
  addFilter(
    'blocks.registerBlockType',
    'gds-block-animations/add-attributes',
    addAttributes,
  );

  addFilter(
    'editor.BlockEdit',
    'gds-block-animations/with-inspector-controls',
    withInspectorControls,
  );

  addFilter(
    'blocks.getSaveContent.extraProps',
    'gds-block-animations/add-save-props',
    addSaveProps,
  );
})(window.wp);
