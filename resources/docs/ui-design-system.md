# UI Design System

This guide sets the quality bar for AI-assisted UI generation in this Laravel, Vue, and Vuetify project.

## Visual Style

- Aim for a modern premium European SaaS feel: clean, spacious, elegant, trustworthy, and calm.
- Interfaces should look intentionally designed, not like generic demos.
- Use restrained depth: subtle borders, soft shadows, and layered surfaces.
- Use soft gradients only where they support hierarchy, such as hero backgrounds or CTA bands.
- Prefer real product context: admin workflows, portfolio views, stock data, watchlists, and personal account management.
- Keep corners rounded but controlled. Use Vuetify `rounded="lg"` or `rounded="xl"` for cards and major surfaces.

## Layout Rules

- Use Vuetify layout primitives first: `v-app`, `v-app-bar`, `v-main`, `v-container`, `v-row`, `v-col`, `v-card`, and `v-footer`.
- Use a strong first viewport: clear brand, direct value proposition, primary CTA, and a product-relevant visual.
- Keep content width constrained. Use `v-container` and avoid edge-to-edge text on desktop.
- Build sections with clear rhythm: hero, trust, features, workflow/value, credibility, CTA, footer.
- Avoid nested cards unless a component naturally frames repeated items or a modal.
- Use consistent vertical section spacing. Large marketing sections should feel calm and breathable.

## Vuetify Component Rules

- Use Vuetify components as the primary UI system. Do not add Tailwind, Bootstrap, shadcn, Nuxt, or another UI framework unless explicitly requested.
- Use `v-btn` for actions, with icons when the action benefits from recognition.
- Use `v-chip` for short labels, states, and trust markers.
- Use `v-card` for repeated feature, workflow, testimonial, and preview items.
- Use `v-icon` from Material Design Icons for consistent icon language.
- Use `v-img` for meaningful product visuals or generated visual assets, with useful `alt` text.
- Use Vuetify color names from the shared theme before introducing local hex values.
- Keep Vuetify props readable. Prefer clear component structure over clever render abstractions.

## Spacing System

- Base rhythm: 8px.
- Small gaps: 8-12px.
- Component gaps: 16-24px.
- Card padding: 24-32px.
- Section padding: 48-72px on desktop, 32-44px on mobile.
- Hero padding may be larger when the first viewport needs stronger presence.
- Use `gap` for grouped elements instead of one-off margins.
- Do not let dynamic labels resize fixed controls or shift compact UI layouts.

## Typography Rules

- Use the existing sans-serif stack unless a deliberate brand typography decision is made.
- Use semantic heading order: one `h1`, then `h2` for sections, `h3` for cards.
- Hero headline: bold, compact line-height, maximum width around 760-820px.
- Body copy: readable line-height around 1.55-1.7 and max width around 620-740px.
- Do not scale font sizes directly with viewport width. Use fixed responsive steps in media queries.
- Letter spacing should stay at `0` unless a small label needs uppercase treatment.
- Avoid placeholder text and lorem ipsum. Use domain-relevant copy.

## Color And Theme Rules

- Use `resources/js/plugins/vuetify.js` as the source of Vuetify theme colors.
- Primary: deep trustworthy green.
- Secondary: quiet blue.
- Accent: warm premium gold/copper.
- Backgrounds: warm off-white and clean white surfaces.
- Use no random colors. Every color should map to a role: background, surface, border, text, primary action, secondary action, accent, success, warning, error, info.
- Keep gradients subtle and low contrast unless used for a focused hero or CTA.
- Maintain accessible contrast for all text and interactive controls.

## Responsive Behavior

- Design mobile first enough that the page still feels intentional at 320px width.
- Collapse navigation links on small screens and keep the primary CTA reachable.
- Stack multi-column rows on mobile using Vuetify grid breakpoints.
- Avoid horizontal scrolling.
- Keep cards, buttons, chips, and headings from overflowing their containers.
- Make touch targets at least 44px high for primary interactions.

## Homepage Section Patterns

- App bar: brand mark, concise nav, primary action.
- Hero: eyebrow chip, strong headline, short supporting copy, primary and secondary CTA, product-relevant visual.
- Trust indicators: concrete product qualities, not fake customer counts or unverified external claims.
- Feature cards: one icon, one clear title, one concise paragraph.
- Workflow/value section: 3 steps or a split layout that explains how users move through the product.
- Credibility section: explain the quality bar, architecture, or product reliability without inventing testimonials.
- Final CTA: repeat the main action with a clear next step.
- Footer: simple brand line and useful context.

## Accessibility Rules

- Use semantic headings and landmarks.
- Provide `alt` text for meaningful images.
- Use descriptive link and button text.
- Preserve keyboard focus visibility.
- Do not rely on color alone to communicate state.
- Keep contrast high enough for text on tinted surfaces.
- Avoid motion that is required to understand content.

## Do Not Do This

- Do not add Tailwind, Bootstrap, shadcn, Nuxt, or another UI framework.
- Do not create generic SaaS filler, fake metrics, fake logos, or fake testimonials.
- Do not use lorem ipsum.
- Do not make the UI a one-color theme.
- Do not use large decorative blobs or random gradient orbs.
- Do not bury the primary action below the fold on mobile.
- Do not create complex custom controls where Vuetify already has a reliable component.
- Do not change dependencies without explicit approval.
- Do not break existing Laravel routes, Vite entries, or admin behavior.
