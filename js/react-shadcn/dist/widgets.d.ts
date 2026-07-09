import React from 'react';
import type { FormField, SpawnClient } from '@spawnflow-dx/core';
export interface WidgetProps {
    field: FormField;
    value: unknown;
    onChange: (value: unknown) => void;
    onBlur: () => void;
    disabled: boolean;
    error?: string;
    client?: SpawnClient;
    subject?: string;
}
export type WidgetComponent = React.ComponentType<WidgetProps>;
export declare const defaultWidgets: Record<string, WidgetComponent>;
