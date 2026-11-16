import { Component, OnInit, inject, input, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage, apiViolations } from '../../core/api.service';
import { REMOTE, SENIORITY } from '../../core/labels';
import { OrgSummary, PriceSuggestion, Tender, TenderDraft } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { EurPipe, SkillsInputComponent } from '../../shared/ui';

@Component({
  selector: 'app-tender-form',
  imports: [ReactiveFormsModule, FormsModule, RouterLink, SkillsInputComponent, EurPipe, IconComponent],
  template: `
    <div class="page">
      <a [routerLink]="id() ? ['/tenders', id()] : '/tenders'" class="back"><app-icon name="arrow-left" [size]="14" />Retour</a>
      <header class="page-head">
        <div>
          <h1>{{ id() ? 'Modifier l\\'appel d\\'offres' : 'Nouvel appel d\\'offres' }}</h1>
          <p class="lead">Le brouillon reste privé jusqu'à sa publication par un acheteur de votre organisation.</p>
        </div>
      </header>

      <div class="grid-form">
        <form class="card" [formGroup]="form" (ngSubmit)="save()" novalidate>
          <div class="form-section">
            <div class="form-section-title"><span class="step">1</span><h2>Le besoin</h2></div>
            <label class="field" [class.has-error]="errors()['title']">
              <span>Intitulé</span>
              <input formControlName="title" placeholder="Ex. Développeur fullstack Symfony / Angular" />
              @if (errors()['title']) { <span class="field-error"><app-icon name="circle-alert" [size]="14" />{{ errors()['title'] }}</span> }
            </label>
            <div class="grid-2">
              <label class="field">
                <span>Séniorité</span>
                <select formControlName="seniority">@for (s of seniorities; track s[0]) { <option [value]="s[0]">{{ s[1] }}</option> }</select>
              </label>
              <label class="field"><span>Charge estimée <small>(jours)</small></span><input type="number" formControlName="estimatedDays" min="1" max="1000" /></label>
            </div>
            <div class="field" [class.has-error]="errors()['skills']">
              <span class="label">Compétences clés</span>
              <app-skills-input formControlName="skills" />
              @if (errors()['skills']) { <span class="field-error"><app-icon name="circle-alert" [size]="14" />{{ errors()['skills'] }}</span> }
            </div>
            <label class="field" [class.has-error]="errors()['description']">
              <span>Description</span>
              <textarea formControlName="description" rows="12" placeholder="Contexte, missions, livrables, profil recherché, modalités…"></textarea>
              @if (errors()['description']) { <span class="field-error"><app-icon name="circle-alert" [size]="14" />{{ errors()['description'] }}</span> }
            </label>
          </div>

          <div class="form-section">
            <div class="form-section-title"><span class="step">2</span><h2>Modalités</h2></div>
            <div class="grid-3">
              <label class="field"><span>Lieu</span><input formControlName="location" /></label>
              <label class="field">
                <span>Télétravail</span>
                <select formControlName="remotePolicy">@for (r of remotes; track r[0]) { <option [value]="r[0]">{{ r[1] }}</option> }</select>
              </label>
              <label class="field"><span>Démarrage</span><input type="date" formControlName="startDate" /></label>
              <label class="field"><span>TJM min <small>(€ HT)</small></span><input type="number" formControlName="budgetMin" min="100" /></label>
              <label class="field" [class.has-error]="errors()['budgetMin']">
                <span>TJM max <small>(€ HT)</small></span><input type="number" formControlName="budgetMax" min="100" />
                @if (errors()['budgetMin']) { <span class="field-error">{{ errors()['budgetMin'] }}</span> }
              </label>
              <label class="field" [class.has-error]="errors()['deadline']">
                <span>Date limite de réponse</span><input type="datetime-local" formControlName="deadline" />
                @if (errors()['deadline']) { <span class="field-error">{{ errors()['deadline'] }}</span> }
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title"><span class="step">3</span><h2>Fournisseurs consultés</h2></div>
            <p class="hint section-intro">Laissez vide pour une consultation ouverte à tous les fournisseurs référencés.</p>
            <div class="check-grid">
              @for (s of suppliers(); track s.id) {
                <label class="check"><input type="checkbox" [checked]="invited().has(s['@id']!)" (change)="toggleInvite(s['@id']!)" />{{ s.name }}</label>
              }
            </div>
          </div>

          <div class="form-actions">
            <a [routerLink]="id() ? ['/tenders', id()] : '/tenders'" class="btn btn-ghost">Annuler</a>
            <button class="btn btn-primary" [disabled]="form.invalid || saving()">
              @if (saving()) { <app-icon name="loader" class="spin" /> } @else { <app-icon name="check" /> }
              Enregistrer le brouillon
            </button>
          </div>
        </form>

        <aside class="ai-panel sticky-aside">
          <div class="card ai">
            <div class="ai-head"><span class="ai-chip"><app-icon name="sparkles" /></span><h2>Assistant de rédaction</h2></div>
            <p class="muted">Décrivez le besoin en quelques lignes : l'agent rédige un appel d'offres structuré et estime le juste prix.</p>
            <textarea rows="6" [(ngModel)]="brief" [ngModelOptions]="{ standalone: true }" aria-label="Brief pour l'assistant"
                      placeholder="Ex. Nous refondons notre portail client en Angular et cherchons un renfort pour 6 mois…"></textarea>
            <button class="btn btn-ai btn-block" type="button" [disabled]="drafting() || brief.trim().length < 20 || !form.value.title" (click)="draft()">
              @if (drafting()) { <app-icon name="loader" class="spin" />Rédaction en cours… } @else { <app-icon name="sparkles" />Générer l'appel d'offres }
            </button>
            @if (!form.value.title) { <p class="hint mt-8">Renseignez d'abord l'intitulé.</p> }
          </div>

          <div class="card">
            <div class="ai-head"><span class="ai-chip"><app-icon name="coins" /></span><h2>Juste prix</h2></div>
            <p class="muted small mb-14">Fourchette de TJM issue des contrats et propositions de la plateforme.</p>
            <button class="btn btn-secondary btn-block" type="button" [disabled]="pricing() || !form.value.skills?.length" (click)="suggestPrice()">
              @if (pricing()) { <app-icon name="loader" class="spin" />Analyse du marché… } @else { <app-icon name="trending" />Estimer le TJM du marché }
            </button>
            @if (price(); as p) {
              <div class="price-range">
                <div><small>Bas</small><b>{{ p.low | eur }}</b></div>
                <div class="median"><small>Médian</small><b>{{ p.median | eur }}</b></div>
                <div><small>Haut</small><b>{{ p.high | eur }}</b></div>
              </div>
              <p class="muted small">{{ p.rationale }}</p>
              <button class="btn btn-ghost btn-sm mt-8" type="button" (click)="applyPrice(p)"><app-icon name="check" [size]="14" />Appliquer cette fourchette</button>
            }
          </div>
        </aside>
      </div>
    </div>
  `,
})
export class TenderFormComponent implements OnInit {
  readonly id = input<string>();
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  readonly seniorities = Object.entries(SENIORITY);
  readonly remotes = Object.entries(REMOTE);
  readonly saving = signal(false);
  readonly drafting = signal(false);
  readonly pricing = signal(false);
  readonly price = signal<PriceSuggestion | null>(null);
  readonly errors = signal<Record<string, string>>({});
  readonly suppliers = signal<OrgSummary[]>([]);
  readonly invited = signal(new Set<string>());
  brief = '';

  readonly form = inject(FormBuilder).group({
    title: ['', [Validators.required, Validators.minLength(5)]],
    seniority: ['confirmed', Validators.required],
    estimatedDays: [60, [Validators.required, Validators.min(1)]],
    skills: [[] as string[], Validators.required],
    description: ['', [Validators.required, Validators.minLength(30)]],
    location: ['Paris', Validators.required],
    remotePolicy: ['hybrid'],
    startDate: [isoDate(45), Validators.required],
    budgetMin: [null as number | null],
    budgetMax: [600, [Validators.required, Validators.min(100)]],
    deadline: [isoDateTime(21), Validators.required],
  });

  ngOnInit(): void {
    this.api.list<OrgSummary>('organizations', { type: 'supplier', itemsPerPage: 100 }).subscribe((c) => this.suppliers.set(c.member));
    const id = this.id();
    if (id) {
      this.api.get<Tender>(`tenders/${id}`).subscribe((t) => {
        this.form.patchValue({ ...t, startDate: t.startDate.slice(0, 10), deadline: toLocalInput(t.deadline) });
        this.invited.set(new Set((t.invitedSuppliers ?? []).map((s) => s['@id']!)));
      });
    }
  }

  toggleInvite(iri: string): void {
    const next = new Set(this.invited());
    next.has(iri) ? next.delete(iri) : next.add(iri);
    this.invited.set(next);
  }

  draft(): void {
    const v = this.form.getRawValue();
    this.drafting.set(true);
    this.api
      .post<TenderDraft>('ai/tender-draft', { title: v.title, context: this.brief, skills: v.skills, seniority: v.seniority, estimatedDays: Number(v.estimatedDays) })
      .subscribe({
        next: (d) => {
          this.drafting.set(false);
          this.form.patchValue({ title: d.title, description: d.description, skills: d.skills, seniority: d.seniority, estimatedDays: d.estimatedDays });
          this.price.set(d.suggestedPrice);
          this.toast.success(d.source === 'llm' ? 'Appel d\'offres rédigé par l\'IA : relisez-le avant d\'enregistrer.' : 'Trame générée (IA non configurée) : complétez-la.');
        },
        error: (e) => {
          this.drafting.set(false);
          this.toast.error(apiErrorMessage(e));
        },
      });
  }

  suggestPrice(): void {
    const v = this.form.getRawValue();
    this.pricing.set(true);
    this.api.post<PriceSuggestion>('ai/price-suggestion', { skills: v.skills, seniority: v.seniority }).subscribe({
      next: (p) => {
        this.pricing.set(false);
        this.price.set(p);
      },
      error: (e) => {
        this.pricing.set(false);
        this.toast.error(apiErrorMessage(e));
      },
    });
  }

  applyPrice(p: PriceSuggestion): void {
    this.form.patchValue({ budgetMin: p.low, budgetMax: p.high });
    this.toast.info('Fourchette de TJM appliquée.');
  }

  save(): void {
    if (this.form.invalid) return;
    const v = this.form.getRawValue();
    const payload = {
      ...v,
      estimatedDays: Number(v.estimatedDays),
      budgetMax: Number(v.budgetMax),
      budgetMin: v.budgetMin ? Number(v.budgetMin) : null,
      deadline: new Date(v.deadline!).toISOString(),
      invitedSuppliers: [...this.invited()],
    };
    this.saving.set(true);
    this.errors.set({});
    const request = this.id() ? this.api.patch<Tender>(`tenders/${this.id()}`, payload) : this.api.post<Tender>('tenders', payload);
    request.subscribe({
      next: (t) => {
        this.toast.success(this.id() ? 'Appel d\'offres mis à jour.' : `Brouillon ${t.reference} créé.`);
        this.router.navigate(['/tenders', t.id]);
      },
      error: (e) => {
        this.saving.set(false);
        this.errors.set(apiViolations(e));
        this.toast.error(apiErrorMessage(e));
      },
    });
  }
}

function isoDate(daysAhead: number): string {
  return new Date(Date.now() + daysAhead * 86_400_000).toISOString().slice(0, 10);
}

function isoDateTime(daysAhead: number): string {
  const d = new Date(Date.now() + daysAhead * 86_400_000);
  d.setHours(18, 0, 0, 0);
  return toLocalInput(d.toISOString());
}

function toLocalInput(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
