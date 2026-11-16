import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { Invoice } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { EurPipe, FrDatePipe, PeriodPipe, SkeletonComponent, StatusBadgeComponent } from '../../shared/ui';
import { WorkflowActionsComponent } from '../../shared/workflow-actions.component';

@Component({
  selector: 'app-invoice-detail',
  imports: [FormsModule, RouterLink, StatusBadgeComponent, SkeletonComponent, EurPipe, FrDatePipe, PeriodPipe, WorkflowActionsComponent, IconComponent],
  template: `
    @if (invoice(); as i) {
      <div class="page">
        <a routerLink="/invoices" class="back no-print"><app-icon name="arrow-left" [size]="14" />Factures</a>
        <header class="page-head no-print">
          <div>
            <p class="eyebrow"><span class="mono">{{ i.contract.reference }}</span><span>·</span>{{ i.contract.consultantName }}</p>
            <div class="title-row"><h1>Facture {{ i.number }}</h1><app-status kind="invoice" [status]="i.status" /></div>
            @if (i.statusComment && i.status === 'rejected') { <p class="callout danger"><app-icon name="circle-alert" />Refusée : {{ i.statusComment }}</p> }
          </div>
          <div class="head-actions">
            @if (editable()) {
              @if (i.status === 'draft') {
                <button class="btn btn-ghost btn-icon" (click)="remove()" aria-label="Supprimer le brouillon" title="Supprimer"><app-icon name="trash" /></button>
              }
              <button class="btn btn-secondary" (click)="editing.set(!editing())"><app-icon [name]="editing() ? 'x' : 'pencil'" />{{ editing() ? 'Fermer' : 'Modifier' }}</button>
            }
            <button class="btn btn-secondary" (click)="print()"><app-icon name="printer" />Imprimer</button>
            <app-workflow-actions resource="invoices" kind="invoice" [subject]="i" (changed)="load()" />
          </div>
        </header>

        @if (editing()) {
          <form class="inline-form no-print" (ngSubmit)="save()">
            <label class="field"><span>Jours travaillés</span><input type="number" step="0.5" min="0.5" [(ngModel)]="draft.daysWorked" name="days" /></label>
            <label class="field grow"><span>Notes</span><input [(ngModel)]="draft.notes" name="notes" /></label>
            <button class="btn btn-primary" [disabled]="saving()">@if (saving()) { <app-icon name="loader" class="spin" /> } Enregistrer</button>
          </form>
        }

        <div class="card no-print section-gap">
          <ol class="steps">
            <li [class.done]="i.submittedAt"><span class="title-row"><app-icon [name]="i.submittedAt ? 'circle-check' : 'send'" />Envoyée</span><small>{{ i.submittedAt ? (i.submittedAt | frDate) : 'En attente' }}</small></li>
            <li [class.done]="i.approvedAt"><span class="title-row"><app-icon [name]="i.approvedAt ? 'circle-check' : 'check'" />Validée</span><small>{{ i.approvedAt ? (i.approvedAt | frDate) + ' · ' + i.approvedBy?.fullName : 'En attente' }}</small></li>
            <li [class.done]="i.paidAt"><span class="title-row"><app-icon [name]="i.paidAt ? 'circle-check' : 'wallet'" />Payée</span><small>{{ i.paidAt ? (i.paidAt | frDate) : 'Échéance ' + (i.dueDate | frDate) }}</small></li>
          </ol>
        </div>

        <article class="card invoice-doc">
          <div class="inv-head">
            <div>
              <h2>{{ i.supplier.name }}</h2>
              <p class="muted small">Émetteur</p>
            </div>
            <div class="right">
              <span class="inv-title">FACTURE</span>
              <span>N° <b class="mono">{{ i.number }}</b></span>
              <span class="muted small">Émise le {{ (i.submittedAt ?? i.createdAt) | frDate }} · échéance {{ i.dueDate | frDate }}</span>
            </div>
          </div>
          <div class="inv-parties">
            <div><small>Facturé à</small><b>{{ i.buyer.name }}</b></div>
            <div><small>Contrat</small><b class="mono">{{ i.contract.reference }}</b> — {{ i.contract.title }}</div>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Désignation</th><th class="num">Quantité</th><th class="num">Prix unitaire HT</th><th class="num">Total HT</th></tr></thead>
              <tbody>
                <tr>
                  <td>Prestation de {{ i.contract.consultantName }} — {{ i.period | period }}</td>
                  <td class="num">{{ i.daysWorked }} j</td>
                  <td class="num">{{ i.dailyRate | eur: true }}</td>
                  <td class="num">{{ i.amountExclTax | eur: true }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="inv-totals">
            <div><span>Total HT</span><b>{{ i.amountExclTax | eur: true }}</b></div>
            <div><span>TVA {{ i.vatRate }} %</span><b>{{ i.vatAmount | eur: true }}</b></div>
            <div class="grand"><span>Total TTC</span><b>{{ i.amountInclTax | eur: true }}</b></div>
          </div>
          @if (i.notes) { <p class="muted small section-gap">Notes : {{ i.notes }}</p> }
          <p class="legal">Paiement à 30 jours. En cas de retard, pénalités au taux légal et indemnité forfaitaire de 40 € pour frais de recouvrement (art. L441-10 du Code de commerce).</p>
        </article>
      </div>
    } @else {
      <app-skeleton [rows]="8" />
    }
  `,
})
export class InvoiceDetailComponent implements OnInit {
  readonly id = input.required<string>();
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  readonly invoice = signal<Invoice | null>(null);
  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly editable = computed(() => this.auth.isSupplier() && ['draft', 'rejected'].includes(this.invoice()?.status ?? ''));
  draft = { daysWorked: 0, notes: '' };

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.api.get<Invoice>(`invoices/${this.id()}`).subscribe({
      next: (i) => {
        this.invoice.set(i);
        this.draft = { daysWorked: i.daysWorked, notes: i.notes ?? '' };
      },
      error: () => {
        this.toast.error('Facture introuvable.');
        this.router.navigate(['/invoices']);
      },
    });
  }

  save(): void {
    this.saving.set(true);
    this.api.patch<Invoice>(`invoices/${this.id()}`, { daysWorked: Number(this.draft.daysWorked), notes: this.draft.notes || null }).subscribe({
      next: () => {
        this.saving.set(false);
        this.editing.set(false);
        this.toast.success('Facture mise à jour.');
        this.load();
      },
      error: (e) => {
        this.saving.set(false);
        this.toast.error(apiErrorMessage(e));
      },
    });
  }

  remove(): void {
    if (!confirm('Supprimer ce brouillon de facture ?')) return;
    this.api.delete(`invoices/${this.id()}`).subscribe({
      next: () => {
        this.toast.success('Brouillon supprimé.');
        this.router.navigate(['/invoices']);
      },
      error: (e) => this.toast.error(apiErrorMessage(e)),
    });
  }

  print(): void {
    window.print();
  }
}
