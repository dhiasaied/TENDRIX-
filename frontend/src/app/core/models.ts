export type Role = 'ROLE_ADMIN' | 'ROLE_BUYER' | 'ROLE_REQUESTER' | 'ROLE_SUPPLIER' | 'ROLE_USER';
export type OrgType = 'buyer' | 'supplier';
export type Seniority = 'junior' | 'confirmed' | 'senior' | 'expert';

export interface Resource {
  '@id'?: string;
  id: number;
  createdAt?: string;
  updatedAt?: string;
}

export interface Collection<T> {
  member: T[];
  totalItems: number;
  view?: { first?: string; last?: string; next?: string; previous?: string };
}

export interface OrgSummary extends Resource {
  name: string;
  type: OrgType;
  city?: string;
  averageRating?: number | null;
}

export interface Organization extends OrgSummary {
  siret?: string | null;
  sector?: string | null;
  website?: string | null;
  description?: string | null;
  skills: string[];
  active: boolean;
}

/** Embedded user reference: e-mail addresses are not shared across organizations. */
export interface UserSummary extends Resource {
  email?: string;
  firstName: string;
  lastName: string;
  fullName: string;
}

export interface User extends UserSummary {
  email: string;
  jobTitle?: string | null;
  phone?: string | null;
  roles: Role[];
  organization: OrgSummary;
  active: boolean;
  lastLoginAt?: string | null;
}

export interface Workflowed extends Resource {
  status: string;
  statusComment?: string | null;
  availableTransitions?: string[];
}

export interface Tender extends Workflowed {
  reference: string;
  title: string;
  description: string;
  skills: string[];
  seniority: Seniority;
  location: string;
  remotePolicy: 'onsite' | 'hybrid' | 'remote';
  startDate: string;
  estimatedDays: number;
  budgetMin?: number | null;
  budgetMax: number;
  deadline: string;
  organization: OrgSummary;
  publishedAt?: string | null;
  acceptingProposals: boolean;
  // Buyer-side only: never sent to suppliers (see RoleGroupsContextBuilder on the API).
  suggestedDailyRate?: number | null;
  createdBy?: UserSummary;
  approvedBy?: UserSummary | null;
  invitedSuppliers?: OrgSummary[];
  proposalsCount?: number;
}

export interface TenderSummary extends Resource {
  reference: string;
  title: string;
  status: string;
}

export interface Proposal extends Workflowed {
  tender: TenderSummary;
  supplier: OrgSummary;
  submittedBy: UserSummary;
  candidateName: string;
  candidateTitle: string;
  candidateSkills: string[];
  experienceYears: number;
  candidateSummary: string;
  dailyRate: number;
  availableFrom: string;
  coverLetter?: string | null;
  matchScore?: number | null;
  matchBreakdown?: Record<string, number> | null;
  matchAnalysis?: string | null;
}

export interface ContractSummary extends Resource {
  reference: string;
  title: string;
  consultantName: string;
  status: string;
}

export interface Evaluation extends Resource {
  quality: number;
  reactivity: number;
  communication: number;
  deadlines: number;
  comment?: string | null;
  overallScore: number;
}

export interface Contract extends Workflowed, ContractSummary {
  tender: TenderSummary;
  buyer: OrgSummary;
  supplier: OrgSummary;
  dailyRate: number;
  startDate: string;
  endDate: string;
  plannedDays: number;
  buyerSignedAt?: string | null;
  buyerSignatory?: UserSummary | null;
  supplierSignedAt?: string | null;
  supplierSignatory?: UserSummary | null;
  evaluation?: Evaluation | null;
  totalValue: number;
  invoicedDays: number;
  remainingDays: number;
  consumptionRate: number;
}

export interface Invoice extends Workflowed {
  number: string;
  contract: ContractSummary;
  supplier: OrgSummary;
  buyer: OrgSummary;
  period: string;
  daysWorked: number;
  dailyRate: number;
  vatRate: number;
  amountExclTax: number;
  amountInclTax: number;
  vatAmount: number;
  notes?: string | null;
  submittedAt?: string | null;
  approvedAt?: string | null;
  approvedBy?: UserSummary | null;
  paidAt?: string | null;
  dueDate?: string | null;
  overdue: boolean;
}

export interface AppNotification {
  id: number;
  type: string;
  title: string;
  message: string;
  link?: string | null;
  read: boolean;
  createdAt: string;
}

export interface Kpi {
  key: string;
  label: string;
  value: number;
  format: 'number' | 'currency' | 'percent' | 'rating';
}

export interface Dashboard {
  space: 'buyer' | 'supplier' | 'admin';
  kpis: Kpi[];
  monthly: { period: string; amount: number }[];
  statusBreakdown: Record<string, Record<string, number>>;
  topPartners: { id: number; name: string; amount: number; rating?: number | null }[];
  actionItems: { type: string; label: string; link: string }[];
}

export interface SupplierPerformance {
  supplierId: number;
  name: string;
  city?: string | null;
  proposalsCount: number;
  acceptedCount: number;
  winRate: number;
  averageMatchScore?: number | null;
  averageDailyRate?: number | null;
  contractsCount: number;
  activeContracts: number;
  totalBilled: number;
  averageRating?: number | null;
  evaluationsCount: number;
  ratingDetails: Record<'quality' | 'reactivity' | 'communication' | 'deadlines', number | null>;
}

export interface PriceSuggestion {
  low: number;
  median: number;
  high: number;
  sampleSize: number;
  basis: string;
  rationale: string;
  source: string;
}

export interface TenderDraft {
  title: string;
  description: string;
  skills: string[];
  seniority: Seniority;
  estimatedDays: number;
  suggestedPrice: PriceSuggestion;
  source: 'llm' | 'template';
}

export interface AuditLog {
  id: number;
  actorEmail?: string | null;
  action: string;
  entityType: string;
  entityId: number;
  payload: Record<string, unknown>;
  createdAt: string;
}
