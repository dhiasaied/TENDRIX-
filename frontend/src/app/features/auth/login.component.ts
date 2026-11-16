import { Component, inject, isDevMode, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { apiErrorMessage } from '../../core/api.service';
import { AuthService, safeReturnUrl } from '../../core/auth.service';
import { IconComponent, IconName } from '../../shared/icon.component';
import { PasswordInputComponent } from '../../shared/password-input.component';
import { AuthHeroComponent } from './auth-hero.component';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, RouterLink, IconComponent, AuthHeroComponent, PasswordInputComponent],
  template: `
    <div class="auth-page">
      <app-auth-hero
        title="De l'appel d'offres à la facture, dans un seul outil."
        lead="Publiez vos besoins, comparez des profils notés par l'IA, contractualisez et pilotez la performance de vos fournisseurs."
        [points]="points" />

      <section class="auth-panel">
        <div class="auth-mobile-brand brand">
          <span class="brand-mark"><app-icon name="layers" [size]="16" [stroke]="2" /></span>
          <span class="brand-name">TEND<b>RIX</b></span>
        </div>
        <div class="auth-card" [class.shake]="shake()">
          <header class="auth-card-head">
            <span class="auth-icon"><app-icon name="key" [size]="20" /></span>
            <h2>Bon retour parmi nous</h2>
            <p>Connectez-vous à votre espace TENDRIX.</p>
          </header>

          <form class="auth-form" [formGroup]="form" (ngSubmit)="submit()" novalidate>
            <div class="field" [class.has-error]="invalid('email')">
              <label class="label" for="login-email">Adresse e-mail</label>
              <div class="control-icon">
                <app-icon name="mail" class="control-lead" />
                <input id="login-email" type="email" formControlName="email" autocomplete="username" placeholder="nom@entreprise.fr"
                       [attr.aria-invalid]="invalid('email')" aria-describedby="login-email-error" />
              </div>
              @if (invalid('email')) {
                <span class="field-error" id="login-email-error"><app-icon name="circle-alert" [size]="13" />
                  {{ form.controls.email.hasError('required') ? 'Saisissez votre adresse e-mail.' : 'Cette adresse e-mail n\\'est pas valide.' }}</span>
              }
            </div>

            <div class="field" [class.has-error]="invalid('password')">
              <label class="label" for="login-password">Mot de passe</label>
              <app-password-input formControlName="password" inputId="login-password" placeholder="Votre mot de passe"
                                  autocomplete="current-password" describedBy="login-password-error" [invalid]="invalid('password')" />
              @if (invalid('password')) {
                <span class="field-error" id="login-password-error"><app-icon name="circle-alert" [size]="13" />Saisissez votre mot de passe.</span>
              }
            </div>

            @if (error()) {
              <p class="form-error" role="alert"><app-icon name="circle-alert" />{{ error() }}</p>
            }
            <button class="btn btn-primary btn-lg btn-block" [disabled]="loading()">
              @if (loading()) { <app-icon name="loader" class="spin" />Connexion… } @else { Se connecter<app-icon name="arrow-right" /> }
            </button>
          </form>

          <p class="auth-switch">Vous êtes un fournisseur ? <a routerLink="/register">Créer un compte</a></p>

          @if (devMode) {
            <div class="demo">
              <p class="demo-title">Comptes de démonstration</p>
              <div class="demo-grid">
                @for (d of demo; track d.email) {
                  <button type="button" class="demo-account" (click)="useDemo(d.email)" [disabled]="loading()">
                    <span class="demo-icon"><app-icon [name]="d.icon" [size]="15" /></span>
                    <span><b>{{ d.role }}</b><small>{{ d.email }}</small></span>
                  </button>
                }
              </div>
            </div>
          }
        </div>
        <p class="auth-legal"><app-icon name="lock" [size]="12" /> Session chiffrée, cookie sécurisé et journal d'audit sur chaque action.</p>
      </section>
    </div>
  `,
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  readonly devMode = isDevMode();
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly shake = signal(false);
  readonly form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  readonly points: { icon: IconName; text: string }[] = [
    { icon: 'sparkles', text: 'Appels d\'offres rédigés et tarifés avec l\'aide de l\'IA' },
    { icon: 'target', text: 'Taux d\'adéquation explicable pour chaque profil proposé' },
    { icon: 'handshake', text: 'Contrats, factures et évaluations centralisés' },
  ];

  /** Seeded by the backend fixtures (dev builds only). */
  readonly demo: { role: string; email: string; icon: IconName }[] = [
    { role: 'Acheteur', email: 'claire.dubois@horizon.test', icon: 'building' },
    { role: 'Donneur d\'ordre', email: 'paul.bernard@horizon.test', icon: 'user' },
    { role: 'Fournisseur', email: 'contact@digitalcraft.test', icon: 'briefcase' },
    { role: 'Administrateur', email: 'admin@tendrix.test', icon: 'shield' },
  ];

  invalid(name: 'email' | 'password'): boolean {
    const control = this.form.controls[name];
    return control.invalid && control.touched;
  }

  useDemo(email: string): void {
    this.form.setValue({ email, password: 'Demo!Tendrix2026' });
    this.submit();
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.nudge();
      return;
    }
    this.loading.set(true);
    this.error.set(null);
    const { email, password } = this.form.getRawValue();
    this.auth.login(email, password).subscribe({
      next: () => this.router.navigateByUrl(safeReturnUrl(this.route.snapshot.queryParamMap.get('returnUrl'))),
      error: (e) => {
        this.loading.set(false);
        this.error.set(e.status === 401 ? 'Adresse e-mail ou mot de passe incorrect.' : apiErrorMessage(e));
        this.nudge();
      },
    });
  }

  /** Brief shake of the card so a rejected submit is noticed. */
  private nudge(): void {
    this.shake.set(false);
    requestAnimationFrame(() => this.shake.set(true));
    setTimeout(() => this.shake.set(false), 420);
  }
}
