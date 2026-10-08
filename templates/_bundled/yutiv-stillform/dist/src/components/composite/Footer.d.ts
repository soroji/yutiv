import { default as React } from 'react';
declare const fields: readonly ["company", "representative", "business_number", "mail_order_number", "address", "phone", "email", "hosting", "verification_url"];
export interface FooterProps {
    id?: string;
    siteName?: string;
    siteDescription?: string;
    copyrightText?: string;
    className?: string;
    editorAttrs?: Record<string, unknown>;
    noticeSlug?: string;
    businessInfo?: Partial<Record<typeof fields[number], string | null>> | null;
    socialLinks?: Record<string, string | undefined>;
    linkGroups?: {
        title: string;
        links: {
            label: string;
            href: string;
        }[];
    }[];
}
declare const Footer: React.FC<FooterProps>;
export default Footer;
