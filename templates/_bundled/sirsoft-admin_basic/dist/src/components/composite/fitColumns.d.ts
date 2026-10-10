/** All widths include cell padding. Below the readable table width allow internal scrolling. */
export declare function fitColumnWidths(columns: {
    field: string;
    width?: string;
}[], containerWidth: number, extras: number, flexibleField: string, minimumWidth?: number, contentMinimums?: Record<string, number>): {
    tableWidth: number;
    minimumRequiredWidth: number;
    widths: number[];
};
