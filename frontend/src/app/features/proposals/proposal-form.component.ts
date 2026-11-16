import { Component, OnInit, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage, apiViolations } from '../../core/api.service';
import { SENIORITY } from '../../core/labels';
import { Proposal, Tender } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { EurPipe, FrDatePipe, SkeletonComponent, SkillsInputComponent } from '../../shared/ui';

/**
 * Used for both "respond to tender" (/tenders/:id/respond) and "edit proposal" (/proposals/:id/edit).
 */
@Component({
  selector: 'app-proposal-form',
  imports: [ReactiveFormsModule, RouterLink, SkillsInputComponent, EurPipe, FrDatePipe, IconComponent, SkeletonComponent],
  template: `
    @if (tender(); as t) {
      <div class="page">
        <a [routerLink]="['/tenders', t.id]" class="back"><app-icon name="arrow-left" [size]="14" />{{ t.reference }}</a>
        <header class="page-head">
          <div>
            <h1>{{ editing() ? 'Modifier la proposition' : 'Proposer un consultant' }}</h1>
            <p class="lead">{{ t.title }} · {{ t.organization.name }} · réponse avant le {{ t.deadline | frDate: true }}</p>
          </div>
        </header>

        <div class="grid-form">
          <form class="card" [formGroup]="form" (ngSubmit)="save()" novalidate>
            <div class="form-section">
              <div class="form-section-title"><span class="step">1</span><h2>Le consultant</h2></div>
              <div class="grid-2">
                <label class="field"><span>Nom complet</span><input formControlName="candidateName" /></label>
                <label class="field"><span>Intitulé du poste</span><input formControlName="candidateTitle" placeholder="Ex. Lead développeur Symfony" /></label>
                <label class="field"><span>Années d'expérience</span><input type="number" formControlName="experienceYears" min="0" max="50" /></label>
                <label class="field"><span>Disponible à partir du</span><input type="date" formControlName="availableFrom" /></label>
              </div>
              <div class="field"><span class="label">Compétences</span><app-skills-input formControlName="candidateSkills" /></div>
              <label class="field" [class.has-error]="errors()['candidateSummary']">
                <span>Résumé du profil / CV</span>
                <textarea formControlName="candidateSummary" rows="10"></textarea>
                <span class="hint">Analysé par l'agent de matching : détaillez missions, environnements et réalisations.</span>
                @if (errors()['candidateSummary']) { <span class="field-error"><app-icon name="circle-alert" [size]="14" />{{ errors()['candidateSummary'] }}</span> }
              </label>
            </div>
            <div class="form-section">
              <div class="form-section-title"><span class="step">2</span><h2>Offre</h2></div>
              <label class="field">
                <span>TJM proposé <small>(€ HT)</small></span>
                <input type="number" formControlName="dailyRate" min="100" />
                @if ((form.value.dailyRate ?? 0) > t.budgetMax) {
                  <span class="warn"><app-icon name="alert" [size]="14" />Au-dessus du budget indiqué ({{ t.budgetMax | eur }}) : le critère tarif sera pénalisé.</span>
                }
              </label>
              <label class="field"><span>Message à l'acheteur <small>(facultatif)</small></span><textarea formControlName="coverLetter" rows="4"></textarea></label>
            </div>
            <div class="form-actions">
              <a [routerLink]="['/tenders', t.id]" class="btn btn-ghost">Annuler</a>
              <button class="btn btn-primary" [disabled]="form.invalid || saving()">
                @if (saving()) { <app-icon name="loader" class="spin" /> } @else { <app-icon name="send" /> }
                {{ editing() ? 'Enregistrer' : 'Envoyer la proposition' }}
              </button>
            </div>
          </form>

          <aside class="card sticky-aside">
            <h2>Rappel du besoin</h2>
            <dl class="facts section-text">
              <dt><app-icon name="award" />Séniorité</dt><dd>{{ seniority(t.seniority) }}</dd>
              <dt><app-icon name="calendar" />Démarrage</dt><dd>{{ t.startDate | frDate }}</dd>
              <dt><app-icon name="clock" />Charge</dt><dd>{{ t.estimatedDays }} jours</dd>
              <dt><app-icon name="coins" />TJM max</dt><dd>{{ t.budgetMax | eur }}</dd>
            </dl>
            <h3>Compétences attendues</h3>
            <div class="chips">@for (s of t.skills; track s) { <span class="chip">{{ s }}</span> }</div>
            <p class="callout ai"><app-icon name="sparkles" />Le taux d'adéquation pondère compétences (45), expérience (20), tarif (20) et disponibilité (15).</p>
          </aside>
        </div>
      </div>
    } @else {
      <app-skeleton [rows]="8" />
    }
  `,
})
export class ProposalFormComponent implements OnInit {
  readonly id = input.required<string>();
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);

  readonly tender = signal<Tender | null>(null);
  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly errors = signal<Record<string, string>>({});

  readonly form = inject(FormBuilder).group({
    candidateName: ['', Validators.required],
    candidateTitle: ['', Validators.required],
    experienceYears: [5, [Validators.required, Validators.min(0)]],
    availableFrom: [new Date().toISOString().slice(0, 10), Validators.required],
    candidateSkills: [[] as string[], Validators.required],
    candidateSummary: ['', [Validators.required, Validators.minLength(50)]],
    dailyRate: [500, [Validators.required, Validators.min(100)]],
    coverLetter: [''],
  });

  ngOnInit(): void {
    this.editing.set(this.route.snapshot.url.some((s) => s.path === 'edit'));
    if (this.editing()) {
      this.api.get<Proposal>(`proposals/${this.id()}`).subscribe((p) => {
        this.form.patchValue({ ...p, availableFrom: p.availableFrom.slice(0, 10) });
        this.api.get<Tender>(`tenders/${p.tender.id}`).subscribe((t) => this.tender.set(t));
      });
    } else {
      this.api.get<Tender>(`tenders/${this.id()}`).subscribe((t) => {
        this.tender.set(t);
        this.form.patchValue({ dailyRate: t.budgetMax, candidateSkills: [] });
      });
    }
  }

  seniority(s: string): string {
    return SENIORITY[s] ?? s;
  }

  save(): void {
    const t = this.tender();
    if (this.form.invalid || !t) return;
    const v = this.form.getRawValue();
    const payload = { ...v, experienceYears: Number(v.experienceYears), dailyRate: Number(v.dailyRate) };
    this.saving.set(true);
    const request = this.editing()
      ? this.api.patch<Proposal>(`proposals/${this.id()}`, payload)
      : this.api.post<Proposal>('proposals', { ...payload, tender: t['@id'] });
    request.subscribe({
      next: (p) => {
        this.toast.success(this.editing() ? 'Proposition mise à jour, nouvelle analyse en cours.' : 'Proposition envoyée. L\'agent de matching l\'analyse.');
        this.router.navigate(['/proposals', p.id]);
      },
      error: (e) => {
        this.saving.set(false);
        this.errors.set(apiViolations(e));
        this.toast.error(apiErrorMessage(e));
      },
    });
  }
}
