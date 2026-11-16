import type { IconName } from '../shared/icon.component';

/** French labels and visual tones for statuses, transitions and enums. */

export type Tone = 'neutral' | 'info' | 'warning' | 'success' | 'danger' | 'accent';

export const STATUS: Record<string, Record<string, [string, Tone]>> = {
  tender: {
    draft: ['Brouillon', 'neutral'],
    pending_approval: ['À valider', 'warning'],
    published: ['Publié', 'info'],
    closed: ['Clôturé', 'accent'],
    awarded: ['Attribué', 'success'],
    cancelled: ['Annulé', 'danger'],
  },
  proposal: {
    submitted: ['Reçue', 'info'],
    shortlisted: ['Présélectionnée', 'accent'],
    rejected: ['Non retenue', 'danger'],
    accepted: ['Retenue', 'success'],
    withdrawn: ['Retirée', 'neutral'],
  },
  contract: {
    pending_buyer_signature: ['Signature client', 'warning'],
    pending_supplier_signature: ['Signature fournisseur', 'warning'],
    active: ['Actif', 'success'],
    completed: ['Terminé', 'neutral'],
    terminated: ['Résilié', 'danger'],
  },
  invoice: {
    draft: ['Brouillon', 'neutral'],
    submitted: ['À valider', 'warning'],
    approved: ['Validée', 'info'],
    rejected: ['Refusée', 'danger'],
    paid: ['Payée', 'success'],
  },
};

export interface TransitionMeta {
  icon: IconName;
  label: string;
  style: 'primary' | 'secondary' | 'danger';
  confirm?: string;
  commentRequired?: boolean;
  commentLabel?: string;
}

export const TRANSITIONS: Record<string, Record<string, TransitionMeta>> = {
  tender: {
    submit: { icon: 'send', label: 'Soumettre à validation', style: 'primary' },
    publish: { icon: 'send', label: 'Publier', style: 'primary', confirm: 'L\'appel d\'offres sera visible par les fournisseurs ciblés.' },
    reject: { icon: 'arrow-left', label: 'Renvoyer en brouillon', style: 'secondary', commentRequired: true, commentLabel: 'Motif du renvoi' },
    close: { icon: 'lock', label: 'Clôturer les réponses', style: 'secondary', confirm: 'Les fournisseurs ne pourront plus répondre.' },
    reopen: { icon: 'arrow-up-right', label: 'Rouvrir', style: 'secondary' },
    cancel: { icon: 'circle-x', label: 'Annuler l\'AO', style: 'danger', commentRequired: true, commentLabel: 'Motif d\'annulation' },
  },
  proposal: {
    shortlist: { icon: 'star', label: 'Présélectionner', style: 'primary' },
    accept: { icon: 'circle-check', label: 'Retenir ce profil', style: 'primary', confirm: 'Le contrat sera généré et les autres propositions seront déclinées.' },
    reject: { icon: 'circle-x', label: 'Décliner', style: 'danger', commentRequired: true, commentLabel: 'Motif (transmis au fournisseur)' },
    withdraw: { icon: 'arrow-left', label: 'Retirer la proposition', style: 'danger', confirm: 'Cette action est définitive.' },
  },
  contract: {
    sign_buyer: { icon: 'pen-line', label: 'Signer le contrat', style: 'primary', confirm: 'Vous signez électroniquement ce contrat au nom de votre organisation.' },
    sign_supplier: { icon: 'pen-line', label: 'Signer le contrat', style: 'primary', confirm: 'Vous signez électroniquement ce contrat au nom de votre organisation.' },
    complete: { icon: 'circle-check', label: 'Clôturer la mission', style: 'secondary', confirm: 'La mission sera marquée comme terminée.' },
    terminate: { icon: 'circle-x', label: 'Résilier', style: 'danger', commentRequired: true, commentLabel: 'Motif de résiliation' },
  },
  invoice: {
    submit: { icon: 'send', label: 'Envoyer la facture', style: 'primary' },
    approve: { icon: 'check', label: 'Valider', style: 'primary' },
    reject: { icon: 'circle-x', label: 'Refuser', style: 'danger', commentRequired: true, commentLabel: 'Motif du refus' },
    pay: { icon: 'wallet', label: 'Marquer comme payée', style: 'primary', confirm: 'Confirmez-vous le règlement de cette facture ?' },
  },
};

export const SENIORITY: Record<string, string> = {
  junior: 'Junior (0-2 ans)',
  confirmed: 'Confirmé (3-5 ans)',
  senior: 'Senior (6-9 ans)',
  expert: 'Expert (10 ans +)',
};

export const REMOTE: Record<string, string> = {
  onsite: 'Sur site',
  hybrid: 'Hybride',
  remote: 'Full remote',
};

export const ROLES: Record<string, string> = {
  ROLE_ADMIN: 'Administrateur',
  ROLE_BUYER: 'Acheteur',
  ROLE_REQUESTER: 'Donneur d\'ordre',
  ROLE_SUPPLIER: 'Fournisseur',
};

export const MATCH_CRITERIA: Record<string, string> = {
  skills: 'Compétences',
  experience: 'Expérience',
  rate: 'Tarif',
  availability: 'Disponibilité',
  aiAdjustment: 'Ajustement IA',
};
