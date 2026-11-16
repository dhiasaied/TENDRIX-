import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { Contract, Invoice } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { EurPipe, ResponsiveTableDirective, FrDatePipe, PeriodPipe, RatingInputComponent, SkeletonComponent, StarsComponent, StatusBadgeComponent } from '../../shared/ui';
import { WorkflowActionsComponent } from '../../shared/workflow-actions.component';

@Component({
  selector: 'app-contract-detail',
  imports: [FormsModule, RouterLink, StatusBadgeComponent, StarsComponent, RatingInputComponent, SkeletonComponent, EurPipe, ResponsiveTableDirective, FrDatePipe, PeriodPipe, WorkflowActionsComponent, IconComponent],
  template: `
    @if (contract(); as c) {
      <div class="page">
        <a routerLink="/contracts" class="back"><app-icon name="arrow-left" [size]="14" />Contrats</a>
        <header class="page-head">
          <div>
            <p class="eyebrow"><span class="mono">{{ c.reference }}</span><span>·</span>issu de <a [routerLink]="['/tenders', c.tender.id]" class="mono">{{ c.tender.reference }}</a></p>
            <div class="title-row"><h1>{{ c.title }}</h1><app-status kind="contract" [status]="c.status" /></div>
            <p class="lead">{{ c.consultantName }} · {{ c.supplier.name }} pour {{ c.buyer.name }}</p>
            @if (c.statusComment) { <p class="callout"><app-icon name="message" />{{ c.statusComment }}</p> }
          </div>
          <div class="head-actions">
            <app-workflow-actions resource="contracts" kind="contract" [subject]="c" (changed)="load()" />
          </div>
        </header>

        <section class="kpis compact">
          <div class="kpi"><div class="kpi-top"><span class="kpi-label">Valeur du contrat</span><span class="kpi-icon"><app-icon name="wallet" /></span></div><span class="kpi-value">{{ c.totalValue | eur }}</span></div>
          <div class="kpi"><div class="kpi-top"><span class="kpi-label">TJM</span><span class="kpi-icon tone-accent"><app-icon name="coins" /></span></div><span class="kpi-value">{{ c.dailyRate | eur }}</span></div>
          <div class="kpi"><div class="kpi-top"><span class="kpi-label">Jours facturés</span><span class="kpi-icon tone-info"><app-icon name="calendar" /></span></div><span class="kpi-value">{{ c.invoicedDays }}<small> / {{ c.plannedDays }}</small></span></div>
          <div class="kpi">
            <div class="kpi-top"><span class="kpi-label">Consommation</span><span class="kpi-inline">{{ c.consumptionRate }} %</span></div>
            <span class="meter wide" [class.success]="c.consumptionRate >= 100"><span [style.width.%]="c.consumptionRate"></span></span>
          </div>
        </section>

        <div class="grid-detail">
          <section class="card">
            <div class="card-head">
              <h2>Factures</h2>
              @if (canInvoice()) {
                <button class="btn btn-primary btn-sm" (click)="showInvoiceForm.set(!showInvoiceForm())" [attr.aria-expanded]="showInvoiceForm()">
                  <app-icon [name]="showInvoiceForm() ? 'x' : 'plus'" [size]="14" />{{ showInvoiceForm() ? 'Fermer' : 'Nouvelle facture' }}
                </button>
              }
            </div>

            @if (showInvoiceForm()) {
              <form class="inline-form" (ngSubmit)="createInvoice()">
                <label class="field"><span>Période</span><input type="month" [(ngModel)]="newInvoice.period" name="period" required [max]="currentPeriod" /></label>
                <label class="field"><span>Jours travaillés</span><input type="number" [(ngModel)]="newInvoice.daysWorked" name="days" step="0.5" min="0.5" [max]="c.remainingDays" required /></label>
                <label class="field grow"><span>Notes <small>(facultatif)</small></span><input [(ngModel)]="newInvoice.notes" name="notes" /></label>
                <div class="inline-total"><small class="subtle">Montant HT</small><b>{{ newInvoice.daysWorked * c.dailyRate | eur }}</b></div>
                <button class="btn btn-primary" [disabled]="saving()">
                  @if (saving()) { <app-icon name="loader" class="spin" /> } Créer le brouillon
                </button>
              </form>
            }

            @if (invoices().length) {
              <div class="table-wrap">
                <table class="table" appResponsive>
                  <thead><tr><th>N°</th><th>Période</th><th class="num">Jours</th><th class="num">Montant TTC</th><th>Échéance</th><th>Statut</th></tr></thead>
                  <tbody>
                    @for (i of invoices(); track i.id) {
                      <tr class="clickable" tabindex="0" [routerLink]="['/invoices', i.id]" (keydown.enter)="router.navigate(['/invoices', i.id])">
                        <td><span class="ref">{{ i.number }}</span></td>
                        <td>{{ i.period | period }}</td>
                        <td class="num">{{ i.daysWorked }}</td>
                        <td class="num">{{ i.amountInclTax | eur: true }}</td>
                        <td [class.over]="i.overdue">{{ i.dueDate | frDate }}</td>
                        <td><app-status kind="invoice" [status]="i.status" /></td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            } @else {
              <p class="muted small">Aucune facture sur ce contrat.</p>
            }
          </section>

          <aside class="stack sticky-aside">
            <div class="card">
              <h2>Signatures</h2>
              <ol class="timeline section-text">
                <li [class.done]="c.buyerSignedAt">
                  <span class="dot"><app-icon [name]="c.buyerSignedAt ? 'check' : 'pen-line'" [size]="12" [stroke]="2" /></span>
                  <span class="body"><b>Client · {{ c.buyer.name }}</b><small>{{ c.buyerSignedAt ? c.buyerSignatory?.fullName + ', ' + (c.buyerSignedAt | frDate: true) : 'En attente de signature' }}</small></span>
                </li>
                <li [class.done]="c.supplierSignedAt">
                  <span class="dot"><app-icon [name]="c.supplierSignedAt ? 'check' : 'pen-line'" [size]="12" [stroke]="2" /></span>
                  <span class="body"><b>Fournisseur · {{ c.supplier.name }}</b><small>{{ c.supplierSignedAt ? c.supplierSignatory?.fullName + ', ' + (c.supplierSignedAt | frDate: true) : 'En attente de signature' }}</small></span>
                </li>
              </ol>
              <dl class="facts section-text">
                <dt><app-icon name="calendar" />Début</dt><dd>{{ c.startDate | frDate }}</dd>
                <dt><app-icon name="calendar-clock" />Fin prévisionnelle</dt><dd>{{ c.endDate | frDate }}</dd>
                <dt><app-icon name="clock" />Jours restants</dt><dd>{{ c.remainingDays }}</dd>
              </dl>
            </div>

            <div class="card">
              <h2>Évaluation du fournisseur</h2>
              @if (c.evaluation; as e) {
                <div class="section-text">
                  <div class="title-row"><span class="card-figure">{{ e.overallScore }}</span><app-stars [value]="e.overallScore" [showValue]="false" [size]="16" /></div>
                  <ul class="criteria two-col">
                    <li><span>Qualité</span><app-stars [value]="e.quality" [showValue]="false" /></li>
                    <li><span>Réactivité</span><app-stars [value]="e.reactivity" [showValue]="false" /></li>
                    <li><span>Communication</span><app-stars [value]="e.communication" [showValue]="false" /></li>
                    <li><span>Respect des délais</span><app-stars [value]="e.deadlines" [showValue]="false" /></li>
                  </ul>
                  @if (e.comment) { <p class="callout"><app-icon name="message" />{{ e.comment }}</p> }
                </div>
              } @else if (canEvaluate()) {
                <form (ngSubmit)="evaluate()" class="section-text">
                  <ul class="criteria two-col">
                    <li><span>Qualité</span><app-rating-input [(value)]="rating.quality" label="Qualité" /></li>
                    <li><span>Réactivité</span><app-rating-input [(value)]="rating.reactivity" label="Réactivité" /></li>
                    <li><span>Communication</span><app-rating-input [(value)]="rating.communication" label="Communication" /></li>
                    <li><span>Respect des délais</span><app-rating-input [(value)]="rating.deadlines" label="Respect des délais" /></li>
                  </ul>
                  <label class="field section-text"><span>Commentaire <small>(visible par le fournisseur)</small></span><textarea rows="3" [(ngModel)]="rating.comment" name="comment"></textarea></label>
                  <button class="btn btn-primary btn-block" [disabled]="saving()"><app-icon name="star" />Enregistrer l'évaluation</button>
                </form>
              } @else {
                <p class="muted small section-text">L'évaluation sera disponible une fois le contrat signé.</p>
              }
            </div>
          </aside>
        </div>
      </div>
    } @else {
      <app-skeleton [rows]="8" />
    }
  `,
})
export class ContractDetailComponent implements OnInit {
  readonly id = input.required<string>();
  readonly auth = inject(AuthService);
  readonly router = inject(Router);
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastService);

  readonly contract = signal<Contract | null>(null);
  readonly invoices = signal<Invoice[]>([]);
  readonly saving = signal(false);
  readonly showInvoiceForm = signal(false);
  readonly currentPeriod = new Date().toISOString().slice(0, 7);
  newInvoice = { period: this.currentPeriod, daysWorked: 20, notes: '' };
  rating = { quality: 4, reactivity: 4, communication: 4, deadlines: 4, comment: '' };

  readonly canInvoice = computed(() => this.auth.isSupplier() && this.contract()?.status === 'active');
  readonly canEvaluate = computed(() => this.auth.isClientSide() && ['active', 'completed', 'terminated'].includes(this.contract()?.status ?? ''));

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.api.get<Contract>(`contracts/${this.id()}`).subscribe({
      next: (c) => this.contract.set(c),
      error: () => {
        this.toast.error('Contrat introuvable.');
        this.router.navigate(['/contracts']);
      },
    });
    this.api.list<Invoice>('invoices', { contract: this.id(), 'order[period]': 'desc', itemsPerPage: 100 }).subscribe((c) => this.invoices.set(c.member));
  }

  createInvoice(): void {
    this.saving.set(true);
    this.api
      .post<Invoice>('invoices', { contract: this.api.iri('contracts', Number(this.id())), period: this.newInvoice.period, daysWorked: Number(this.newInvoice.daysWorked), notes: this.newInvoice.notes || null })
      .subscribe({
        next: (i) => {
          this.saving.set(false);
          this.toast.success(`Facture ${i.number} créée en brouillon.`);
          this.router.navigate(['/invoices', i.id]);
        },
        error: (e) => {
          this.saving.set(false);
          this.toast.error(apiErrorMessage(e));
        },
      });
  }

  evaluate(): void {
    this.saving.set(true);
    this.api.post('supplier_evaluations', { contract: this.api.iri('contracts', Number(this.id())), ...this.rating, comment: this.rating.comment || null }).subscribe({
      next: () => {
        this.saving.set(false);
        this.toast.success('Évaluation enregistrée.');
        this.load();
      },
      error: (e) => {
        this.saving.set(false);
        this.toast.error(apiErrorMessage(e));
      },
    });
  }
}
