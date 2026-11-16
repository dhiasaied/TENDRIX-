import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { REMOTE, SENIORITY } from '../../core/labels';
import { Proposal, Tender } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { EmptyStateComponent, EurPipe, ResponsiveTableDirective, FrDatePipe, ScoreComponent, SkeletonComponent, StatusBadgeComponent } from '../../shared/ui';
import { WorkflowActionsComponent } from '../../shared/workflow-actions.component';

@Component({
  selector: 'app-tender-detail',
  imports: [RouterLink, StatusBadgeComponent, ScoreComponent, EmptyStateComponent, SkeletonComponent, EurPipe, ResponsiveTableDirective, FrDatePipe, WorkflowActionsComponent, IconComponent],
  template: `
    @if (tender(); as t) {
      <div class="page">
        <a routerLink="/tenders" class="back"><app-icon name="arrow-left" [size]="14" />{{ auth.isSupplier() ? 'Opportunités' : 'Appels d\\'offres' }}</a>
        <header class="page-head">
          <div>
            <p class="eyebrow"><span class="mono">{{ t.reference }}</span><span>·</span>{{ t.organization.name }}</p>
            <div class="title-row"><h1>{{ t.title }}</h1><app-status kind="tender" [status]="t.status" /></div>
            @if (t.statusComment) {
              <p class="callout"><app-icon name="message" />{{ t.statusComment }}</p>
            }
          </div>
          <div class="head-actions">
            @if (canEdit()) {
              @if (t.status === 'draft') {
                <button class="btn btn-ghost btn-icon" (click)="remove()" aria-label="Supprimer le brouillon" title="Supprimer"><app-icon name="trash" /></button>
              }
              <a [routerLink]="['/tenders', t.id, 'edit']" class="btn btn-secondary"><app-icon name="pencil" />Modifier</a>
            }
            @if (auth.isSupplier() && t.acceptingProposals) {
              <a [routerLink]="['/tenders', t.id, 'respond']" class="btn btn-primary"><app-icon name="send" />Proposer un consultant</a>
            }
            <app-workflow-actions resource="tenders" kind="tender" [subject]="t" (changed)="load()" />
          </div>
        </header>

        <div class="grid-detail">
          <section class="card">
            <h2>Description du besoin</h2>
            <div class="prose section-text">{{ t.description }}</div>
            <h3>Compétences attendues</h3>
            <div class="chips">@for (s of t.skills; track s) { <span class="chip">{{ s }}</span> }</div>
          </section>

          <aside class="card sticky-aside">
            <dl class="facts">
              <dt><app-icon name="award" />Séniorité</dt><dd>{{ seniority(t.seniority) }}</dd>
              <dt><app-icon name="pin" />Lieu</dt><dd>{{ t.location }} · {{ remote(t.remotePolicy) }}</dd>
              <dt><app-icon name="calendar" />Démarrage</dt><dd>{{ t.startDate | frDate }}</dd>
              <dt><app-icon name="clock" />Charge</dt><dd>{{ t.estimatedDays }} jours</dd>
              <dt><app-icon name="coins" />TJM</dt><dd>@if (t.budgetMin) { {{ t.budgetMin | eur }} – } {{ t.budgetMax | eur }}</dd>
              @if (t.suggestedDailyRate && !auth.isSupplier()) {
                <dt><app-icon name="sparkles" />Juste prix IA</dt><dd class="ai">{{ t.suggestedDailyRate | eur }} / j</dd>
              }
              <dt><app-icon name="calendar-clock" />Réponses avant</dt><dd>{{ t.deadline | frDate: true }}</dd>
              @if (t.invitedSuppliers) {
                <dt><app-icon name="users" />Consultation</dt><dd>{{ t.invitedSuppliers.length ? 'Restreinte · ' + t.invitedSuppliers.length : 'Ouverte' }}</dd>
              }
              @if (t.createdBy) { <dt><app-icon name="user" />Rédigé par</dt><dd>{{ t.createdBy.fullName }}</dd> }
              @if (t.approvedBy) { <dt><app-icon name="circle-check" />Publié par</dt><dd>{{ t.approvedBy.fullName }}</dd> }
            </dl>
          </aside>
        </div>

        <section class="card">
          <div class="card-head">
            <div>
              <h2>{{ auth.isSupplier() ? 'Vos propositions' : 'Propositions reçues' }}</h2>
              @if (!auth.isSupplier() && proposals().length > 1) { <span class="subtle">Classées par taux d'adéquation IA</span> }
            </div>
            <span class="pill">{{ proposals().length }}</span>
          </div>
          @if (!proposals().length) {
            <app-empty icon="proposal" [title]="auth.isSupplier() ? 'Vous n\\'avez pas encore répondu' : 'Aucune proposition pour le moment'"
                       [hint]="auth.isSupplier() ? null : 'Les fournisseurs ciblés ont été notifiés à la publication.'" />
          } @else {
            <div class="table-wrap">
              <table class="table" appResponsive>
                <thead><tr>@if (!auth.isSupplier()) { <th>Adéquation</th> }<th>Candidat</th>@if (!auth.isSupplier()) { <th>Fournisseur</th> }<th>Expérience</th><th class="num">TJM</th><th>Disponible</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                  @for (p of proposals(); track p.id) {
                    <tr class="clickable" tabindex="0" [routerLink]="['/proposals', p.id]" (keydown.enter)="open(p)">
                      @if (!auth.isSupplier()) { <td><app-score [value]="p.matchScore" /></td> }
                      <td><span class="cell-main">{{ p.candidateName }}</span><span class="cell-sub">{{ p.candidateTitle }}</span></td>
                      @if (!auth.isSupplier()) { <td>{{ p.supplier.name }}</td> }
                      <td>{{ p.experienceYears }} ans</td>
                      <td class="num" [class.over]="p.dailyRate > t.budgetMax">{{ p.dailyRate | eur }}</td>
                      <td>{{ p.availableFrom | frDate }}</td>
                      <td><app-status kind="proposal" [status]="p.status" /></td>
                      <td class="cell-arrow"><app-icon name="chevron-right" /></td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
        </section>
      </div>
    } @else {
      <app-skeleton [rows]="8" />
    }
  `,
})
export class TenderDetailComponent implements OnInit {
  readonly id = input.required<string>();
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  readonly tender = signal<Tender | null>(null);
  readonly proposals = signal<Proposal[]>([]);
  readonly canEdit = computed(() => {
    const t = this.tender();
    if (!t || !this.auth.isClientSide()) return false;
    if (this.auth.isBuyer()) return ['draft', 'pending_approval'].includes(t.status);
    return t.status === 'draft' && t.createdBy?.id === this.auth.session()?.id;
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.api.get<Tender>(`tenders/${this.id()}`).subscribe({
      next: (t) => this.tender.set(t),
      error: () => {
        this.toast.error('Appel d\'offres introuvable.');
        this.router.navigate(['/tenders']);
      },
    });
    this.api.list<Proposal>('proposals', { tender: this.id(), 'order[matchScore]': 'desc', itemsPerPage: 100 }).subscribe((c) => this.proposals.set(c.member));
  }

  open(p: Proposal): void {
    this.router.navigate(['/proposals', p.id]);
  }

  remove(): void {
    if (!confirm('Supprimer définitivement ce brouillon ?')) return;
    this.api.delete(`tenders/${this.id()}`).subscribe({
      next: () => {
        this.toast.success('Brouillon supprimé.');
        this.router.navigate(['/tenders']);
      },
      error: (e) => this.toast.error(apiErrorMessage(e)),
    });
  }

  seniority(s: string): string {
    return SENIORITY[s] ?? s;
  }

  remote(r: string): string {
    return REMOTE[r] ?? r;
  }
}
