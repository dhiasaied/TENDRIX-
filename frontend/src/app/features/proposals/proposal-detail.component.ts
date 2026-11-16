import { Component, OnDestroy, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { MATCH_CRITERIA } from '../../core/labels';
import { Proposal, Workflowed } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { EurPipe, FrDatePipe, ScoreComponent, SkeletonComponent, StatusBadgeComponent } from '../../shared/ui';
import { WorkflowActionsComponent } from '../../shared/workflow-actions.component';

const MAX_POINTS: Record<string, number> = { skills: 45, experience: 20, rate: 20, availability: 15 };

@Component({
  selector: 'app-proposal-detail',
  imports: [RouterLink, StatusBadgeComponent, ScoreComponent, SkeletonComponent, EurPipe, FrDatePipe, WorkflowActionsComponent, IconComponent],
  template: `
    @if (proposal(); as p) {
      <div class="page">
        <a [routerLink]="['/tenders', p.tender.id]" class="back"><app-icon name="arrow-left" [size]="14" />{{ p.tender.reference }} · {{ p.tender.title }}</a>
        <header class="page-head">
          <div>
            <p class="eyebrow">Proposition de {{ p.supplier.name }} · {{ p.createdAt | frDate }}</p>
            <div class="title-row"><h1>{{ p.candidateName }}</h1><app-status kind="proposal" [status]="p.status" /></div>
            <p class="lead">{{ p.candidateTitle }} · {{ p.experienceYears }} ans d'expérience</p>
            @if (p.statusComment) { <p class="callout"><app-icon name="message" />{{ p.statusComment }}</p> }
          </div>
          <div class="head-actions">
            @if (auth.isSupplier() && p.status === 'submitted') {
              <a [routerLink]="['/proposals', p.id, 'edit']" class="btn btn-secondary"><app-icon name="pencil" />Modifier</a>
            }
            <app-workflow-actions resource="proposals" kind="proposal" [subject]="p" (changed)="onChanged($event)" />
          </div>
        </header>

        <div class="grid-detail">
          <section class="card">
            <h2>Profil du consultant</h2>
            <div class="prose section-text">{{ p.candidateSummary }}</div>
            <h3>Compétences</h3>
            <div class="chips">@for (s of p.candidateSkills; track s) { <span class="chip">{{ s }}</span> }</div>
            @if (p.coverLetter) {
              <h3>Message du fournisseur</h3>
              <div class="prose small">{{ p.coverLetter }}</div>
            }
          </section>

          <aside class="stack sticky-aside">
            <!-- AI scoring is a buyer-side decision aid; the API never sends it to suppliers. -->
            @if (!auth.isSupplier()) {
            <div class="card ai">
              <div class="match-head">
                <app-score [value]="p.matchScore" [size]="64" />
                <div>
                  <div class="title-row"><h2>Taux d'adéquation</h2><span class="ai-tag"><app-icon name="sparkles" [size]="11" />IA</span></div>
                  <small class="muted">{{ pending() ? 'Analyse en cours par l\\'agent de matching…' : 'Pondération : compétences, expérience, tarif, disponibilité' }}</small>
                </div>
              </div>
              @if (criteria().length) {
                <ul class="criteria">
                  @for (c of criteria(); track c.key) {
                    <li>
                      <span>{{ c.label }}</span>
                      @if (c.max) {
                        <span class="meter ai"><span [style.width.%]="(c.value / c.max) * 100"></span></span>
                        <b>{{ c.value }}<span class="subtle">/{{ c.max }}</span></b>
                      } @else {
                        <span></span><b>{{ c.value > 0 ? '+' : '' }}{{ c.value }}</b>
                      }
                    </li>
                  }
                </ul>
              }
              @if (p.matchAnalysis) { <p class="callout ai"><app-icon name="sparkles" />{{ p.matchAnalysis }}</p> }
            </div>
            }
            <div class="card">
              <dl class="facts">
                <dt><app-icon name="coins" />TJM proposé</dt><dd>{{ p.dailyRate | eur }}</dd>
                <dt><app-icon name="calendar" />Disponibilité</dt><dd>{{ p.availableFrom | frDate }}</dd>
                <dt><app-icon name="briefcase" />Fournisseur</dt><dd>{{ p.supplier.name }}</dd>
                <dt><app-icon name="user" />Déposée par</dt><dd>{{ p.submittedBy.fullName }}</dd>
              </dl>
            </div>
          </aside>
        </div>
      </div>
    } @else {
      <app-skeleton [rows]="8" />
    }
  `,
})
export class ProposalDetailComponent implements OnInit, OnDestroy {
  readonly id = input.required<string>();
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private poll?: ReturnType<typeof setTimeout>;

  readonly proposal = signal<Proposal | null>(null);
  readonly pending = computed(() => this.proposal()?.matchScore === null || this.proposal()?.matchScore === undefined);
  readonly criteria = computed(() =>
    Object.entries(this.proposal()?.matchBreakdown ?? {}).map(([key, value]) => ({ key, value, label: MATCH_CRITERIA[key] ?? key, max: MAX_POINTS[key] })),
  );

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    clearTimeout(this.poll);
  }

  load(): void {
    this.api.get<Proposal>(`proposals/${this.id()}`).subscribe({
      next: (p) => {
        this.proposal.set(p);
        // The score is computed asynchronously: refresh until it is available.
        if (!this.auth.isSupplier() && (p.matchScore === null || p.matchScore === undefined)) this.poll = setTimeout(() => this.load(), 3000);
      },
      error: () => {
        this.toast.error('Proposition introuvable.');
        this.router.navigate(['/proposals']);
      },
    });
  }

  onChanged(updated: Workflowed): void {
    if ((updated as Proposal).status === 'accepted') {
      this.toast.success('Profil retenu : le contrat a été généré.');
      this.router.navigate(['/contracts']);
      return;
    }
    this.load();
  }
}
