import { Component, input } from '@angular/core';
import { IconComponent, IconName } from '../../shared/icon.component';

/** Brand panel shared by the sign-in and registration pages. */
@Component({
  selector: 'app-auth-hero',
  imports: [IconComponent],
  template: `
    <section class="auth-hero">
      <div class="brand">
        <span class="brand-mark"><app-icon name="layers" [size]="16" [stroke]="2" /></span>
        <span class="brand-name">TEND<b>RIX</b></span>
      </div>
      <div class="hero-body">
        <span class="hero-kicker"><app-icon name="sparkles" [size]="14" />{{ kicker() }}</span>
        <h1>{{ title() }}</h1>
        <p class="lead">{{ lead() }}</p>
        <ul class="hero-points">
          @for (p of points(); track p.text) {
            <li><span class="tick"><app-icon [name]="p.icon" [size]="13" [stroke]="2" /></span>{{ p.text }}</li>
          }
        </ul>
        <div class="hero-preview" aria-hidden="true">
          <div><small>Appel d'offres</small><b>3 profils</b><span class="up"><app-icon name="sparkles" [size]="12" />notés par l'IA</span></div>
          <div><small>Meilleure adéquation</small><b>91 / 100</b><span class="up"><app-icon name="check" [size]="12" />dans le budget</span></div>
          <div><small>Contrat</small><b>Signé</b><span class="up"><app-icon name="pen-line" [size]="12" />par les 2 parties</span></div>
        </div>
      </div>
      <div class="hero-foot">
        <span><app-icon name="shield" [size]="14" />Données isolées par organisation</span>
        <span><app-icon name="lock" [size]="14" />Accès par rôle</span>
        <span><app-icon name="history" [size]="14" />Journal d'audit complet</span>
      </div>
    </section>
  `,
})
export class AuthHeroComponent {
  readonly kicker = input('Achats de prestations intellectuelles');
  readonly title = input('');
  readonly lead = input('');
  readonly points = input<{ icon: IconName; text: string }[]>([]);
}
