import { Injectable } from '@angular/core';
import { AppError } from '../../app.interfaces';
import type { AssetSlotName } from './asset.service';
import { nrwPlayerStyles } from './nrw-player-styles';

@Injectable({
  providedIn: 'root'
})
export class ThemeService {
  activeTheme: Theme;
  availableThemes = THEMES;

  constructor() {
    this.activeTheme = THEMES[0];
    document.body.className = this.activeTheme.cssClassName;
  }

  setTheme(themeName: string = this.availableThemes[0].name) {
    const newTheme = THEMES.find(theme => theme.name === themeName);
    if (!newTheme) {
      throw new AppError({
        type: 'warning',
        description: '',
        label: `Theme "${themeName}" konnte nicht geladen werden.`
      });
    }
    this.activeTheme = newTheme;
    document.body.className = this.activeTheme.cssClassName;
  }

  /**
   * Players run in their own document (iframe srcdoc), so the theme classes on the host body don't reach them.
   * Themes that bring player styles get them appended to the player's html (after the player's own styles).
   */
  applyToPlayer(playerHtml: string): string {
    const css = this.activeTheme.playerStyles?.(document.baseURI);
    return css ? `${playerHtml}<style id="tc-theme">${css}</style>` : playerHtml;
  }
}

export interface Theme {
  name: string;
  cssClassName: string;
  previewColor?: string;
  description?: string;
  targetAudience: 'children' | 'teenager' | 'adults';
  imagePaths?: Partial<Record<AssetSlotName, string>>;
  playerStyles?: (baseUri: string) => string;
}

export const THEMES: Theme[] = [
  {
    name: 'Primar',
    cssClassName: 'theme-primar',
    previewColor: '#196175',
    description: 'Zielgruppe Schüler*innen der Primarstufe',
    targetAudience: 'children'
  },
  {
    name: 'Sekundar',
    cssClassName: 'theme-sekundar',
    previewColor: '#0B2D84',
    description: 'Zielgruppe Schüler*innen der Sekundarstufe I',
    targetAudience: 'teenager',
    imagePaths: {
      codeInputIllustration: 'assets/images/code-input-illustration-teens.png'
    }
  },
  {
    name: 'Erwachsene',
    cssClassName: 'theme-adult',
    previewColor: '#6B369A',
    description: 'Zielgruppe Erwachsenen (z. B. Lehrkräfte)',
    targetAudience: 'adults'
  },
  {
    name: 'NRW',
    cssClassName: 'theme-nrw',
    previewColor: '#031A38',
    description: 'Design System NRW',
    targetAudience: 'children',
    playerStyles: nrwPlayerStyles
  }
];
