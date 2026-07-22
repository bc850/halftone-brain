export type OrganizationSummary = {
    id: number;
    name: string;
    slug: string;
};

export type Tenant = {
    organization: OrganizationSummary | null;
    permissions: string[];
    parentPermissions: string[];
    canManageParent: boolean;
    organizations: OrganizationSummary[];
};
