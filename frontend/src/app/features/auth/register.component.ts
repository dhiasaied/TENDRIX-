import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { apiErrorMessage, apiViolations } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { ToastService } from '../../core/toast.service';
import { IconComponent, IconName } from '../../shared/icon.component';
import { PasswordInputComponent } from '../../shared/password-input.component';
import { SkillsInputComponent } from '../../shared/ui';
import { AuthHeroComponent } from './auth-hero.component';

type Field = 'companyName' | 'siret' | 'firstName' | 'lastName' | 'email' | 'city' | 'password';

@Component({
  selector: 'app-register',
  imports: [ReactiveFormsModule, RouterLink, SkillsInputComponent, IconComponent, AuthHeroComponent, PasswordInputComponent],
  template: `
    <div class="auth-page">
      <app-auth-hero
        kicker="Espace fournisseur"
        title="Accédez aux appels d'offres de grands comptes."
        lead="Rejoignez le réseau de prestataires référencés et répondez aux besoins qui correspondent à vos expertises."
        [points]="points" />

      <section class="auth-panel">
        <div class="auth-mobile-brand brand">
          <span class="brand-mark"><app-icon name="layers" [size]="16" [stroke]="2" /></span>
          <span class="brand-name">TEND<b>RIX</b></span>
        </div>
        <div class="auth-card wide" [class.shake]="shake()">
          <header class="auth-card-head">
            <span class="auth-icon"><app-icon name="briefcase" [size]="20" /></span>
            <h2>Créer un compte fournisseur</h2>
            <p>Quelques informations suffisent pour commencer.</p>
          </header>

          <form class="auth-form" [formGroup]="form" (ngSubmit)="submit()" novalidate>
            <div class="auth-section">
              <p class="auth-section-title"><span class="num">1</span>Votre société</p>
              <div class="grid-2">
                <div class="field" [class.has-error]="invalid('companyName')">
                  <label class="label" for="reg-company">Raison sociale</label>
                  <div class="control-icon"><app-icon name="building" class="control-lead" />
                    <input id="reg-company" formControlName="companyName" autocomplete="organization" placeholder="Digital Craft" /></div>
                  @if (invalid('companyName')) { <span class="field-error"><app-icon name="circle-alert" [size]="13" />Indiquez la raison sociale.</span> }
                </div>
                <div class="field" [class.has-error]="invalid('siret')">
                  <label class="label" for="reg-siret">SIRET <small class="subtle">(facultatif)</small></label>
                  <div class="control-icon"><app-icon name="contract" class="control-lead" />
                    <input id="reg-siret" formControlName="siret" inputmode="numeric" maxlength="14" placeholder="14 chiffres" /></div>
                  @if (invalid('siret')) { <span class="field-error"><app-icon name="circle-alert" [size]="13" />Le SIRET compte exactement 14 chiffres.</span> }
                </div>
              </div>
              <div class="field">
                <label class="label" for="reg-city">Ville <small class="subtle">(facultatif)</small></label>
                <div class="control-icon"><app-icon name="pin" class="control-lead" />
                  <input id="reg-city" formControlName="city" autocomplete="address-level2" placeholder="Lyon" /></div>
              </div>
              <div class="field"><span class="label">Expertises</span><app-skills-input formControlName="skills" /><span class="hint">Utilisées pour vous notifier des opportunités pertinentes.</span></div>
            </div>

            <div class="auth-section">
              <p class="auth-section-title"><span class="num">2</span>Votre accès</p>
              <div class="grid-2">
                <div class="field" [class.has-error]="invalid('firstName')">
                  <label class="label" for="reg-first">Prénom</label>
                  <input id="reg-first" formControlName="firstName" autocomplete="given-name" />
                  @if (invalid('firstName')) { <span class="field-error"><app-icon name="circle-alert" [size]="13" />Indiquez votre prénom.</span> }
                </div>
                <div class="field" [class.has-error]="invalid('lastName')">
                  <label class="label" for="reg-last">Nom</label>
                  <input id="reg-last" formControlName="lastName" autocomplete="family-name" />
                  @if (invalid('lastName')) { <span class="field-error"><app-icon name="circle-alert" [size]="13" />Indiquez votre nom.</span> }
                </div>
              </div>
              <div class="field" [class.has-error]="invalid('email')">
                <label class="label" for="reg-email">E-mail professionnel</label>
                <div class="control-icon"><app-icon name="mail" class="control-lead" />
                  <input id="reg-email" type="email" formControlName="email" autocomplete="email" placeholder="nom@entreprise.fr" /></div>
                @if (invalid('email')) {
                  <span class="field-error"><app-icon name="circle-alert" [size]="13" />
                    {{ form.controls.email.hasError('required') ? 'Indiquez votre e-mail professionnel.' : 'Cette adresse e-mail n\\'est pas valide.' }}</span>
                }
              </div>
              <div class="field" [class.has-error]="invalid('password')">
                <label class="label" for="reg-password">Mot de passe</label>
                <app-password-input formControlName="password" inputId="reg-password" autocomplete="new-password"
                                    placeholder="12 caractères minimum" describedBy="reg-password-hint" [invalid]="invalid('password')" [showStrength]="true" />
                @if (invalid('password')) {
                  <span class="field-error"><app-icon name="circle-alert" [size]="13" />Le mot de passe doit contenir au moins 12 caractères.</span>
                } @else {
                  <span class="hint" id="reg-password-hint">Mélangez lettres, chiffres et symboles. Les mots de passe ayant fuité sont refusés.</span>
                }
              </div>
            </div>

            @for (msg of fieldErrors(); track msg) {
              <p class="form-error" role="alert"><app-icon name="circle-alert" />{{ msg }}</p>
            }
            <button class="btn btn-primary btn-lg btn-block" [disabled]="loading()">
              @if (loading()) { <app-icon name="loader" class="spin" />Création du compte… } @else { Créer mon compte<app-icon name="arrow-right" /> }
            </button>
          </form>
          <p class="auth-switch">Déjà inscrit ? <a routerLink="/login">Se connecter</a></p>
        </div>
        <p class="auth-legal"><app-icon name="lock" [size]="12" /> Vos données restent isolées dans l'espace de votre organisation.</p>
      </section>
    </div>
  `,
})
export class RegisterComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  readonly points: { icon: IconName; text: string }[] = [
    { icon: 'bell', text: 'Recevez les opportunités alignées sur vos expertises' },
    { icon: 'send', text: 'Proposez vos consultants et suivez vos réponses en temps réel' },
    { icon: 'wallet', text: 'Signez, facturez et suivez vos paiements au même endroit' },
  ];
  readonly loading = signal(false);
  readonly shake = signal(false);
  readonly fieldErrors = signal<string[]>([]);
  readonly form = inject(FormBuilder).nonNullable.group({
    companyName: ['', Validators.required],
    siret: ['', Validators.pattern(/^\d{14}$/)],
    firstName: ['', Validators.required],
    lastName: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    city: [''],
    skills: [[] as string[]],
    password: ['', [Validators.required, Validators.minLength(12)]],
  });

  invalid(name: Field): boolean {
    const control = this.form.controls[name];
    return control.invalid && control.touched;
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.nudge();
      return;
    }
    this.loading.set(true);
    this.fieldErrors.set([]);
    const value = this.form.getRawValue();
    this.auth.register({ ...value, siret: value.siret || null }).subscribe({
      next: () => {
        this.auth.login(value.email, value.password).subscribe(() => {
          this.toast.success('Bienvenue sur TENDRIX.');
          this.router.navigate(['/dashboard']);
        });
      },
      error: (e) => {
        this.loading.set(false);
        const violations = Object.values(apiViolations(e));
        this.fieldErrors.set(violations.length ? violations : [apiErrorMessage(e)]);
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
