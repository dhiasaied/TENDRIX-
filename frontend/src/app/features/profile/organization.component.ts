import { Component, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { Organization, User } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { InitialsPipe, SkeletonComponent, SkillsInputComponent, StarsComponent } from '../../shared/ui';

@Component({
  selector: 'app-organization',
  imports: [ReactiveFormsModule, SkillsInputComponent, StarsComponent, IconComponent, InitialsPipe, SkeletonComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div><h1>Mon entreprise</h1><p class="lead">Ces informations sont visibles par vos partenaires sur la plateforme.</p></div>
      </header>
      @if (org(); as o) {
        <div class="grid-form">
          <form class="card" [formGroup]="form" (ngSubmit)="save(o)">
            <div class="form-section">
              <div class="form-section-title"><span class="step"><app-icon name="building" [size]="12" /></span><h2>Identité</h2></div>
              <div class="grid-2">
                <label class="field"><span>Raison sociale</span><input formControlName="name" /></label>
                <label class="field"><span>SIRET</span><input formControlName="siret" maxlength="14" inputmode="numeric" /></label>
                <label class="field"><span>Secteur</span><input formControlName="sector" /></label>
                <label class="field"><span>Ville</span><input formControlName="city" /></label>
              </div>
              <label class="field"><span>Site web</span><input type="url" formControlName="website" placeholder="https://" /></label>
              <label class="field"><span>Présentation</span><textarea rows="5" formControlName="description"></textarea></label>
            </div>
            @if (o.type === 'supplier') {
              <div class="form-section">
                <div class="form-section-title"><span class="step"><app-icon name="target" [size]="12" /></span><h2>Expertises</h2></div>
                <div class="field"><app-skills-input formControlName="skills" /><span class="hint">Utilisées pour vous notifier des opportunités pertinentes.</span></div>
              </div>
            }
            <div class="form-actions">
              <button class="btn btn-primary" [disabled]="form.pristine || saving()">
                @if (saving()) { <app-icon name="loader" class="spin" /> } @else { <app-icon name="check" /> }
                Enregistrer
              </button>
            </div>
          </form>
          <aside class="card sticky-aside">
            <dl class="facts">
              <dt><app-icon name="layers" />Type</dt><dd>{{ o.type === 'supplier' ? 'Fournisseur' : 'Client acheteur' }}</dd>
              @if (o.averageRating) { <dt><app-icon name="star" />Note moyenne</dt><dd><app-stars [value]="o.averageRating" [size]="13" /></dd> }
            </dl>
            <h3>Membres · {{ members().length }}</h3>
            <ul class="members">
              @for (m of members(); track m.id) {
                <li><span class="avatar">{{ m.fullName | initials }}</span><div><b>{{ m.fullName }}</b><small class="subtle">{{ m.jobTitle ?? m.email }}</small></div></li>
              }
            </ul>
          </aside>
        </div>
      } @else {
        <app-skeleton />
      }
    </div>
  `,
})
export class OrganizationComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  readonly org = signal<Organization | null>(null);
  readonly members = signal<User[]>([]);
  readonly saving = signal(false);
  readonly form = inject(FormBuilder).nonNullable.group({
    name: [''],
    siret: [''],
    sector: [''],
    city: [''],
    website: [''],
    description: [''],
    skills: [[] as string[]],
  });

  ngOnInit(): void {
    const id = this.auth.organization()?.id;
    if (!id) return;
    this.api.get<Organization>(`organizations/${id}`).subscribe((o) => {
      this.org.set(o);
      this.form.patchValue({ ...o, siret: o.siret ?? '', sector: o.sector ?? '', city: o.city ?? '', website: o.website ?? '', description: o.description ?? '' });
      this.form.markAsPristine();
    });
    this.api.list<User>('users', { organization: id }).subscribe((c) => this.members.set(c.member));
  }

  save(o: Organization): void {
    const v = this.form.getRawValue();
    this.saving.set(true);
    this.api.patch<Organization>(`organizations/${o.id}`, { ...v, siret: v.siret || null, website: v.website || null }).subscribe({
      next: (updated) => {
        this.saving.set(false);
        this.org.set(updated);
        this.form.markAsPristine();
        this.toast.success('Informations mises à jour.');
      },
      error: (e) => {
        this.saving.set(false);
        this.toast.error(apiErrorMessage(e));
      },
    });
  }
}
