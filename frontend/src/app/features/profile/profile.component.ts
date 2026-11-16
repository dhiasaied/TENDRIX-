import { Component, effect, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { ROLES } from '../../core/labels';
import { User } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { FrDatePipe, InitialsPipe, SkeletonComponent } from '../../shared/ui';

@Component({
  selector: 'app-profile',
  imports: [ReactiveFormsModule, FrDatePipe, InitialsPipe, IconComponent, SkeletonComponent],
  template: `
    <div class="page">
      <header class="page-head"><div><h1>Mon profil</h1><p class="lead">Vos informations personnelles et vos accès.</p></div></header>
      @if (auth.profile(); as u) {
        <div class="grid-form">
          <form class="card" [formGroup]="form" (ngSubmit)="save(u)">
            <div class="form-section">
              <div class="form-section-title"><span class="step"><app-icon name="user" [size]="12" /></span><h2>Informations personnelles</h2></div>
              <div class="grid-2">
                <label class="field"><span>Prénom</span><input formControlName="firstName" autocomplete="given-name" /></label>
                <label class="field"><span>Nom</span><input formControlName="lastName" autocomplete="family-name" /></label>
                <label class="field"><span>Fonction</span><input formControlName="jobTitle" autocomplete="organization-title" /></label>
                <label class="field"><span>Téléphone</span><input formControlName="phone" autocomplete="tel" /></label>
              </div>
            </div>
            <div class="form-section">
              <div class="form-section-title"><span class="step"><app-icon name="key" [size]="12" /></span><h2>Mot de passe</h2></div>
              <div class="grid-2">
                <label class="field">
                  <span>Nouveau mot de passe <small>(laisser vide pour conserver l'actuel)</small></span>
                  <input type="password" formControlName="password" autocomplete="new-password" />
                  <span class="hint">12 caractères minimum, robuste et jamais utilisé ailleurs.</span>
                </label>
                @if (form.value.password) {
                  <label class="field">
                    <span>Mot de passe actuel</span>
                    <input type="password" formControlName="currentPassword" autocomplete="current-password" />
                    <span class="hint">Les autres appareils connectés seront déconnectés.</span>
                  </label>
                }
              </div>
            </div>
            <div class="form-actions">
              <button class="btn btn-primary" [disabled]="form.invalid || form.pristine || saving()">
                @if (saving()) { <app-icon name="loader" class="spin" /> } @else { <app-icon name="check" /> }
                Enregistrer
              </button>
            </div>
          </form>
          <aside class="card sticky-aside">
            <div class="org-id">
              <span class="avatar lg">{{ u.fullName | initials }}</span>
              <div><h3>{{ u.fullName }}</h3><small class="subtle">{{ u.email }}</small></div>
            </div>
            <dl class="facts section-text">
              <dt><app-icon name="building" />Organisation</dt><dd>{{ u.organization.name }}</dd>
              <dt><app-icon name="shield" />Rôle</dt><dd>{{ roles(u) }}</dd>
              <dt><app-icon name="clock" />Dernière connexion</dt><dd>{{ u.lastLoginAt | frDate: true }}</dd>
              <dt><app-icon name="calendar" />Membre depuis</dt><dd>{{ u.createdAt | frDate }}</dd>
            </dl>
          </aside>
        </div>
      } @else {
        <app-skeleton />
      }
    </div>
  `,
})
export class ProfileComponent {
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastService);
  readonly saving = signal(false);
  readonly form = inject(FormBuilder).nonNullable.group({
    firstName: ['', Validators.required],
    lastName: ['', Validators.required],
    jobTitle: [''],
    phone: [''],
    password: ['', Validators.minLength(12)],
    currentPassword: [''],
  });

  constructor() {
    effect(() => {
      const u = this.auth.profile();
      if (u) {
        this.form.patchValue({ firstName: u.firstName, lastName: u.lastName, jobTitle: u.jobTitle ?? '', phone: u.phone ?? '' });
        this.form.markAsPristine();
      }
    });
  }

  roles(u: User): string {
    return u.roles.filter((r) => r !== 'ROLE_USER').map((r) => ROLES[r]).join(', ');
  }

  save(u: User): void {
    const { password, currentPassword, ...rest } = this.form.getRawValue();
    if (password && !currentPassword) {
      this.toast.error('Saisissez votre mot de passe actuel pour le modifier.');
      return;
    }
    this.saving.set(true);
    this.api.patch<User>(`users/${u.id}`, { ...rest, phone: rest.phone || null, ...(password ? { password, currentPassword } : {}) }).subscribe({
      next: () => {
        this.saving.set(false);
        this.form.controls.password.reset('');
        this.form.controls.currentPassword.reset('');
        this.auth.loadProfile();
        this.toast.success('Profil mis à jour.');
      },
      error: (e) => {
        this.saving.set(false);
        this.toast.error(apiErrorMessage(e));
      },
    });
  }
}
