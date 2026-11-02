const FONT = "'Open Sans', ui-sans-serif, system-ui, sans-serif";
const PRIMARY = '#031A38';
// NRW button blue (portal-blue-600), as the host's filled buttons. Keep in sync with themes.scss.
const BUTTON_BLUE = '#2769B7';
const ICON_SIZE = '20px';
const BUTTON_RADIUS = '10px';
const BUTTON_MIN_HEIGHT = '48px';

const FONT_FILES: { weight: number, style: 'normal' | 'italic', file: string }[] = [
  { weight: 400, style: 'normal', file: 'open-sans-latin-400-normal.woff2' },
  { weight: 400, style: 'italic', file: 'open-sans-latin-400-italic.woff2' },
  { weight: 600, style: 'normal', file: 'open-sans-latin-600-normal.woff2' },
  { weight: 700, style: 'normal', file: 'open-sans-latin-700-normal.woff2' },
  { weight: 700, style: 'italic', file: 'open-sans-latin-700-italic.woff2' }
];

// The Aspect player hard-codes its Material colours (teal #006064, indigo #3f51b5, pink #ff4081) as component tokens
// on several selectors (html, .mat-accent, .mat-mdc-radio-button.mat-accent, ...). These are the tokens of the
// controls the player renders (radio, checkbox, text fields, dropdown, slider, tabs of the math keyboard).
const SOLID_TOKENS = [
  'radio-checked-ripple-color',
  'radio-selected-focus-icon-color',
  'radio-selected-hover-icon-color',
  'radio-selected-icon-color',
  'radio-selected-pressed-icon-color',
  'checkbox-selected-focus-icon-color',
  'checkbox-selected-hover-icon-color',
  'checkbox-selected-icon-color',
  'checkbox-selected-pressed-icon-color',
  'checkbox-selected-focus-state-layer-color',
  'checkbox-selected-hover-state-layer-color',
  'checkbox-selected-pressed-state-layer-color',
  'pseudo-checkbox-full-selected-icon-color',
  'pseudo-checkbox-minimal-selected-checkmark-color',
  'form-field-filled-caret-color',
  'form-field-filled-focus-active-indicator-color',
  'form-field-outlined-caret-color',
  'form-field-outlined-focus-outline-color',
  'select-focused-arrow-color',
  'option-selected-state-label-text-color',
  'slider-active-track-color',
  'slider-focus-handle-color',
  'slider-handle-color',
  'slider-hover-handle-color',
  'slider-inactive-track-color',
  'slider-ripple-color',
  'slider-with-tick-marks-inactive-container-color',
  'tab-active-label-text-color',
  'tab-active-ripple-color',
  'tab-inactive-ripple-color',
  'tab-active-focus-label-text-color',
  'tab-active-hover-label-text-color',
  'tab-active-focus-indicator-color',
  'tab-active-hover-indicator-color',
  'tab-active-indicator-color'
];

// Same tokens as the player ships them, with the same transparency (focus labels 87%, state layers 12% / 4%).
const TRANSPARENT_TOKENS: [string, number][] = [
  ['form-field-focus-select-arrow-color', 87],
  ['form-field-filled-focus-label-text-color', 87],
  ['form-field-outlined-focus-label-text-color', 87],
  ['slider-focus-state-layer-color', 12],
  ['slider-hover-state-layer-color', 4]
];

/**
 * Stylesheet for the NRW theme inside a player iframe. The player lives in its own document (srcdoc), so it neither
 * inherits the host's theme classes nor its fonts: the @font-face rules are repeated here with absolute URLs.
 * Unit-authored fonts are overridden everywhere except for icon and math fonts.
 */
export function nrwPlayerStyles(baseUri: string): string {
  const fontUrl = (file: string) => new URL(`assets/fonts/${file}`, baseUri).href;
  const fontFaces = FONT_FILES
    .map(f => `@font-face{font-family:'Open Sans';font-style:${f.style};font-weight:${f.weight};font-display:swap;` +
      `src:url('${fontUrl(`opensans/${f.file}`)}') format('woff2')}`)
    .join('');
  const tokens = [
    ...SOLID_TOKENS.map(t => `--mat-${t}:${PRIMARY}`),
    ...TRANSPARENT_TOKENS.map(([t, percent]) => `--mat-${t}:color-mix(in srgb,${PRIMARY} ${percent}%,transparent)`)
  ].map(declaration => `${declaration} !important`).join(';');
  return `${fontFaces}` +
    `html,body{font-family:${FONT}}` +
    // Form controls (input, textarea, button) don't inherit the font by default, so every element is targeted.
    `body :not(.mat-icon):not(.material-icons):not(.katex):not(.katex *){font-family:${FONT} !important}` +
    // Overlays (dropdown panel) live outside <aspect-player>, hence html and every descendant.
    `html,html *{${tokens}}` +
    // Action buttons of a unit (<aspect-button>): the unit sets background, color and border-radius inline, so
    // !important. Same look as the host's filled buttons. Disabled buttons keep Material's disabled look.
    // The unit's layout cell also dictates the height (often ~24px for a 20px label), so the button gets a minimum
    // height (NRW touch target is 44px) and vertical padding of its own.
    `aspect-button button.mat-mdc-button:not([disabled]){background-color:${BUTTON_BLUE} !important;color:#fff !important;` +
    `border-radius:${BUTTON_RADIUS} !important;min-height:${BUTTON_MIN_HEIGHT};padding:12px 24px;line-height:1.4;` +
    `--mat-button-text-state-layer-color:#fff;--mat-button-text-ripple-color:#fff}` +
    `.material-icons{font-size:${ICON_SIZE}}` +
    `.mat-icon.material-icons{width:${ICON_SIZE};height:${ICON_SIZE}}`;
}
