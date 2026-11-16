import { Component, HostListener, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { Subject, debounceTime } from 'rxjs';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { ROLES } from '../../core/labels';
import { OrgSummary, User } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { FrDatePipe, InitialsPipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, TableSkeletonComponent } from '../../shared/ui';

@Component({
  selector: 'app-admin-users',
  imports: [FormsModule, ReactiveFormsModule, FrDatePipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, TableSkeletonComponent, IconComponent, InitialsPipe],
  template: `
    <div class="page">
      <header class="page-head">
        <div><h1>Utilisateurs</h1><p class="lead">Comptes, rôles et accès à la plateforme.</p></div>
        <button class="btn btn-primary" (click)="creating.set(true)"><app-icon name="plus" />Nouvel utilisateur</button>
      </header>
      <section class="data-card">
      <div class="data-toolbar">
        <app-search placeholder="Nom de famille" label="Rechercher" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        <select class="filter" [class.active]="organization" [(ngModel)]="organization" (ngModelChange)="reload(1)" aria-label="Filtrer par organisation">
          <option value="">Toutes les organisations</option>
          @for (o of organizations(); track o.id) { <option [value]="o.id">{{ o.name }}</option> }
        </select>
        <span class="spacer"></span>
        @if (search || organization) { <button type="button" class="btn-reset" (click)="search = ''; organization = ''; reload(1)"><app-icon name="reset" [size]="14" />Réinitialiser</button> }
        <span class="result-count" aria-live="polite"><b>{{ total() }}</b> résultat{{ total() > 1 ? 's' : '' }}</span>
      </div>
      @if (loading()) {
        <app-table-skeleton [cols]="6" />
      } @else {
        <div class="table-wrap">
            <table class="table" appResponsive>
              <thead><tr><th>Utilisateur</th><th>Organisation</th><th>Rôle</th><th>Dernière connexion</th><th>Statut</th><th></th></tr></thead>
              <tbody>
                @for (u of items(); track u.id) {
                  <tr>
                    <td><div class="org-id"><span class="avatar">{{ u.fullName | initials }}</span><div><span class="cell-main">{{ u.fullName }}</span><span class="cell-sub">{{ u.email }}</span></div></div></td>
                    <td>{{ u.organization.name }}</td>
                    <td><span class="badge plain tone-neutral">{{ roleLabels(u) }}</span></td>
                    <td class="subtle">{{ u.lastLoginAt ? (u.lastLoginAt | frDate: true) : 'Jamais' }}</td>
                    <td><span [class]="'badge ' + (u.active ? 'tone-success' : 'tone-danger')">{{ u.active ? 'Actif' : 'Désactivé' }}</span></td>
                    <td class="num">
                      @if (u.id !== auth.session()?.id) {
                        <button class="btn btn-ghost btn-sm" (click)="toggle(u)"><app-icon [name]="u.active ? 'lock' : 'check'" [size]="14" />{{ u.active ? 'Désactiver' : 'Réactiver' }}</button>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        <div class="data-foot"><app-pagination [page]="page()" [total]="total()" (pageChange)="reload($event)" /></div>
      }
      </section>
    </div>

    @if (creating()) {
      <div class="modal-backdrop" (click)="creating.set(false)">
        <form class="modal" [formGroup]="form" (ngSubmit)="create()" (click)="$event.stopPropagation()" role="dialog" aria-modal="true" aria-labelledby="user-title">
          <div class="modal-head">
            <div class="modal-icon"><app-icon name="user-cog" [size]="18" /></div>
            <div><h3 id="user-title">Nouvel utilisateur</h3><p>Le compte est actif immédiatement avec le mot de passe initial.</p></div>
          </div>
          <div class="grid-2">
            <label class="field"><span>Prénom</span><input formControlName="firstName" autofocus /></label>
            <label class="field"><span>Nom</span><input formControlName="lastName" /></label>
          </div>
          <label class="field"><span>E-mail</span><input type="email" formControlName="email" /></label>
          <div class="grid-2">
            <label class="field"><span>Organisation</span>
              <select formControlName="organization">
                <option value="" disabled>Choisir…</option>
                @for (o of organizations(); track o.id) { <option [value]="o['@id']">{{ o.name }}</option> }
              </select>
            </label>
            <label class="field"><span>Rôle</span>
              <select formControlName="role">@for (r of roleOptions; track r[0]) { <option [value]="r[0]">{{ r[1] }}</option> }</select>
            </label>
          </div>
          <label class="field"><span>Mot de passe initial</span><input type="password" formControlName="password" autocomplete="new-password" /><span class="hint">12 caractères minimum, mot de passe robuste.</span></label>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" (click)="creating.set(false)">Annuler</button>
            <button class="btn btn-primary" [disabled]="form.invalid"><app-icon name="plus" />Créer</button>
          </div>
        </form>
      </div>
    }
  `,
})
export class AdminUsersComponent implements OnInit {
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastService);
  readonly items = signal<User[]>([]);
  readonly organizations = signal<OrgSummary[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly creating = signal(false);
  readonly roleOptions = Object.entries(ROLES);
  readonly search$ = new Subject<void>();
  search = '';
  organization = '';
  readonly form = inject(FormBuilder).nonNullable.group({
    firstName: ['', Validators.required],
    lastName: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    organization: ['', Validators.required],
    role: ['ROLE_BUYER', Validators.required],
    password: ['', [Validators.required, Validators.minLength(12)]],
  });

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload(1));
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.creating.set(false);
  }

  ngOnInit(): void {
    this.api.list<OrgSummary>('organizations', { itemsPerPage: 100 }).subscribe((c) => this.organizations.set(c.member));
    this.reload(1);
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.api.list<User>('users', { page, lastName: this.search, organization: this.organization }).subscribe((c) => {
      this.items.set(c.member);
      this.total.set(c.totalItems);
      this.loading.set(false);
    });
  }

  roleLabels(u: User): string {
    return u.roles.filter((r) => r !== 'ROLE_USER').map((r) => ROLES[r]).join(', ');
  }

  toggle(u: User): void {
    this.api.patch<User>(`users/${u.id}`, { active: !u.active }).subscribe({
      next: () => {
        this.toast.success(u.active ? 'Compte désactivé.' : 'Compte réactivé.');
        this.reload(this.page());
      },
      error: (e) => this.toast.error(apiErrorMessage(e)),
    });
  }

  create(): void {
    const { role, ...v } = this.form.getRawValue();
    this.api.post<User>('users', { ...v, roles: [role] }).subscribe({
      next: (u) => {
        this.creating.set(false);
        this.form.reset({ role: 'ROLE_BUYER' });
        this.toast.success(`Compte créé pour ${u.fullName}.`);
        this.reload(1);
      },
      error: (e) => this.toast.error(apiErrorMessage(e)),
    });
  }
}
