# GDS Animate WordPress Plugin

A WordPress plugin that adds animation classes to blocks on scroll. Simple and flexible - no dependencies, no build process!

## How It Works

The plugin adds two classes to enabled blocks:

1. **`gds-block-animations-block`** - Added immediately when page loads
2. **`gds-animate-visible`** - Added when block enters viewport (with 50ms delay for blocks already visible on load)

**You style the animations in your theme CSS!**

## Features

- ✨ Pure PHP, CSS, and JS - no build tools needed
- 🎨 Simple class-based system - style animations in your theme
- 📱 Respects user's motion preferences
- 🎯 Admin settings page to enable/disable per block
- 🚀 Performant using IntersectionObserver API
- ♿ Accessibility-friendly

## Installation

1. Activate the plugin through the 'Plugins' menu in WordPress
2. Go to Settings → GDS Animate to enable blocks
3. Add animation styles in your theme CSS

## Usage

### 1. Enable Blocks (Admin)

1. Navigate to **Settings → GDS Animate** in WordPress admin
2. Browse all available blocks grouped by category:
   - **Text** - Paragraph, Heading, List, etc.
   - **Media** - Image, Gallery, Video, Audio, etc.
   - **Design** - Button, Columns, Group, Spacer, etc.
   - **Widgets** - Shortcode, Archives, Calendar, etc.
   - **Theme** - Navigation, Site Logo, Template Parts, etc.
   - **Embeds** - YouTube, Twitter, Instagram, etc.
3. Check the blocks you want to animate
4. Click **Save Changes**

Each block shows its title and technical name (e.g., `core/media-text`)

### 2. Disable Per-Block (Editor)

You can disable animation for individual blocks:

1. Select any enabled block in the editor
2. Look for **Animation Settings** panel in the sidebar (Inspector Controls)
3. Toggle **"Disable scroll animation"** to turn off animation for that specific block
4. Animation will be disabled only for that block instance

### 3. Style Animations (Theme CSS)

The plugin just adds classes - you create the animations! Example:

```css
/* Query Loop with staggered cards */
.wp-block-query.gds-block-animations-block .wp-block-gds-post-teaser {
  opacity: 0;
  transform: translateY(20px);
  transition:
    opacity 0.5s ease,
    transform 0.5s ease;
}

.wp-block-query.gds-animate-visible .wp-block-gds-post-teaser:nth-child(1) {
  opacity: 1;
  transform: translateY(0);
  transition-delay: 0s;
}

.wp-block-query.gds-animate-visible .wp-block-gds-post-teaser:nth-child(2) {
  opacity: 1;
  transform: translateY(0);
  transition-delay: 0.1s;
}

.wp-block-query.gds-animate-visible .wp-block-gds-post-teaser:nth-child(3) {
  opacity: 1;
  transform: translateY(0);
  transition-delay: 0.2s;
}
/* Continue for more items... */
```

### 4. Debug (Console)

```javascript
gdsAnimate();
```

Shows enabled blocks and animation counts.

## File Structure

```
gds-animate/
├── gds-animate.php    # Main plugin with settings
├── assets/
│   ├── style.css      # Example styles (optional)
│   ├── script.js      # Class management logic (frontend)
│   └── editor.js      # Block editor controls
└── README.md          # This file
```

## Development

### Adding New Blocks

**1. Update PHP (`gds-animate.php`):**

- Add to default options array
- Add checkbox in settings form

**2. Update JS (`assets/script.js`):**

- Add to `blockClassMap` object

**3. Style in Theme:**

- Add CSS for `.your-block.gds-block-animations-block` and `.your-block.gds-animate-visible`

## Technical Details

- IntersectionObserver triggers when 10% of block is visible
- 100px bottom margin offset (animates slightly before fully visible)
- Blocks are observed once (not repeated on scroll up/down)
- 50ms delay for blocks visible on page load ensures smooth animation

## License

GPL-2.0+
