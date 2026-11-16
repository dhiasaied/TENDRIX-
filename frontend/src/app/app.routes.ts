import { Routes } from '@angular/router';
import { authGuard, guestGuard, roleGuard } from './core/guards';
import { ShellComponent } from './layout/shell.component';

const CLIENT = ['ROLE_BUYER', 'ROLE_REQUESTER'];
const MARKET = ['ROLE_BUYER', 'ROLE_REQUESTER', 'ROLE_SUPPLIER'];

export const routes: Routes = [
  { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./features/auth/login.component').then((m) => m.LoginComponent), title: 'Connexion · TENDRIX' },
  { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./features/auth/register.component').then((m) => m.RegisterComponent), title: 'Inscription fournisseur · TENDRIX' },
  {
    path: '',
    component: ShellComponent,
    canActivate: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      { path: 'dashboard', loadComponent: () => import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent), title: 'Tableau de bord · TENDRIX' },

      { path: 'tenders', canActivate: [roleGuard], data: { roles: MARKET }, loadComponent: () => import('./features/tenders/tender-list.component').then((m) => m.TenderListComponent), title: 'Appels d\'offres · TENDRIX' },
      { path: 'tenders/new', canActivate: [roleGuard], data: { roles: CLIENT }, loadComponent: () => import('./features/tenders/tender-form.component').then((m) => m.TenderFormComponent), title: 'Nouvel appel d\'offres · TENDRIX' },
      { path: 'tenders/:id/edit', canActivate: [roleGuard], data: { roles: CLIENT }, loadComponent: () => import('./features/tenders/tender-form.component').then((m) => m.TenderFormComponent), title: 'Modifier l\'appel d\'offres · TENDRIX' },
      { path: 'tenders/:id/respond', canActivate: [roleGuard], data: { roles: ['ROLE_SUPPLIER'] }, loadComponent: () => import('./features/proposals/proposal-form.component').then((m) => m.ProposalFormComponent), title: 'Répondre · TENDRIX' },
      { path: 'tenders/:id', canActivate: [roleGuard], data: { roles: MARKET }, loadComponent: () => import('./features/tenders/tender-detail.component').then((m) => m.TenderDetailComponent), title: 'Appel d\'offres · TENDRIX' },

      { path: 'proposals', canActivate: [roleGuard], data: { roles: MARKET }, loadComponent: () => import('./features/proposals/proposal-list.component').then((m) => m.ProposalListComponent), title: 'Propositions · TENDRIX' },
      { path: 'proposals/:id/edit', canActivate: [roleGuard], data: { roles: ['ROLE_SUPPLIER'] }, loadComponent: () => import('./features/proposals/proposal-form.component').then((m) => m.ProposalFormComponent), title: 'Modifier la proposition · TENDRIX' },
      { path: 'proposals/:id', canActivate: [roleGuard], data: { roles: MARKET }, loadComponent: () => import('./features/proposals/proposal-detail.component').then((m) => m.ProposalDetailComponent), title: 'Proposition · TENDRIX' },

      { path: 'contracts', canActivate: [roleGuard], data: { roles: MARKET }, loadComponent: () => import('./features/contracts/contract-list.component').then((m) => m.ContractListComponent), title: 'Contrats · TENDRIX' },
      { path: 'contracts/:id', canActivate: [roleGuard], data: { roles: MARKET }, loadComponent: () => import('./features/contracts/contract-detail.component').then((m) => m.ContractDetailComponent), title: 'Contrat · TENDRIX' },

      { path: 'invoices', canActivate: [roleGuard], data: { roles: ['ROLE_BUYER', 'ROLE_SUPPLIER'] }, loadComponent: () => import('./features/invoices/invoice-list.component').then((m) => m.InvoiceListComponent), title: 'Factures · TENDRIX' },
      { path: 'invoices/:id', canActivate: [roleGuard], data: { roles: ['ROLE_BUYER', 'ROLE_SUPPLIER'] }, loadComponent: () => import('./features/invoices/invoice-detail.component').then((m) => m.InvoiceDetailComponent), title: 'Facture · TENDRIX' },

      { path: 'suppliers', loadComponent: () => import('./features/suppliers/supplier-performance.component').then((m) => m.SupplierPerformanceComponent), title: 'Performance fournisseurs · TENDRIX' },
      { path: 'directory', canActivate: [roleGuard], data: { roles: CLIENT }, loadComponent: () => import('./features/suppliers/directory.component').then((m) => m.DirectoryComponent), title: 'Annuaire · TENDRIX' },
      { path: 'organization', canActivate: [roleGuard], data: { roles: ['ROLE_SUPPLIER', 'ROLE_BUYER'] }, loadComponent: () => import('./features/profile/organization.component').then((m) => m.OrganizationComponent), title: 'Mon entreprise · TENDRIX' },
      { path: 'notifications', loadComponent: () => import('./features/notifications/notifications.component').then((m) => m.NotificationsComponent), title: 'Notifications · TENDRIX' },
      { path: 'profile', loadComponent: () => import('./features/profile/profile.component').then((m) => m.ProfileComponent), title: 'Mon profil · TENDRIX' },

      { path: 'admin/organizations', canActivate: [roleGuard], data: { roles: ['ROLE_ADMIN'] }, loadComponent: () => import('./features/admin/organizations.component').then((m) => m.AdminOrganizationsComponent), title: 'Organisations · TENDRIX' },
      { path: 'admin/users', canActivate: [roleGuard], data: { roles: ['ROLE_ADMIN'] }, loadComponent: () => import('./features/admin/users.component').then((m) => m.AdminUsersComponent), title: 'Utilisateurs · TENDRIX' },
      { path: 'admin/audit', canActivate: [roleGuard], data: { roles: ['ROLE_ADMIN'] }, loadComponent: () => import('./features/admin/audit.component').then((m) => m.AdminAuditComponent), title: 'Journal d\'audit · TENDRIX' },
    ],
  },
  { path: '**', redirectTo: '' },
];
