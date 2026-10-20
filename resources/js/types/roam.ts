export interface Option {
    key: string;
    label: string;
}

export interface Pace extends Option {
    stops: number;
}

export interface DestinationLite {
    id: number;
    slug: string;
    name: string;
    country_name: string | null;
    summary?: string | null;
    places_count?: number;
    saved?: boolean;
}

export interface Place {
    id: number;
    name: string;
    slug: string;
    category: string;
    interests: string[];
    description: string | null;
    latitude: number;
    longitude: number;
    neighbourhood: string | null;
    website: string | null;
    duration: number;
    has_opening_hours: boolean;
    accessibility_note: string | null;
    verified: boolean;
}

export interface Stop {
    id: number;
    position: number;
    name: string;
    latitude: number | null;
    longitude: number | null;
    start_time: string | null;
    duration_minutes: number;
    notes: string | null;
    source: 'generated' | 'manual';
    is_locked: boolean;
    time_locked: boolean;
    warnings: string[];
    category: string | null;
    place_id: number | null;
    description: string | null;
    neighbourhood: string | null;
    website: string | null;
    accessibility_note: string | null;
    verified: boolean;
}

export interface Day {
    id: number;
    position: number;
    date: string;
    title: string | null;
    notes: string | null;
    stops: Stop[];
}

export interface PlanWarning {
    code: string;
    message: string;
}

export interface TripData {
    id: number;
    title: string;
    start_date: string;
    end_date: string;
    day_count: number;
    travelers: number;
    currency: string;
    pace: string;
    status: 'upcoming' | 'in_progress' | 'completed';
    warnings: PlanWarning[];
    generated_at: string | null;
    updated_at: string;
    notes?: string | null;
    summary?: string | null;
    budget_minor?: number | null;
    budget_input?: string;
    destination: {
        id: number;
        slug: string;
        name: string;
        country_name: string | null;
        latitude: number;
        longitude: number;
        timezone: string | null;
        currency_code: string | null;
        summary: string | null;
    };
    source_attribution: string | null;
    preferences?: {
        interests: string[];
        accessibility: string | null;
        dietary: string | null;
        accommodation: string | null;
        accommodation_latitude: number | null;
        accommodation_longitude: number | null;
    };
}

export interface BudgetCategory {
    key: string;
    name: string;
    estimated_minor: number;
    actual_minor: number;
    count: number;
}

export interface Budget {
    currency: string;
    exponent: number;
    budget_minor: number | null;
    estimated_minor: number;
    actual_minor: number;
    estimated_per_traveler_minor: number | null;
    remaining_minor: number | null;
    remaining_after_actuals_minor: number | null;
    categories: BudgetCategory[];
    excluded_without_rate: number;
}

export interface Expense {
    id: number;
    category_id: number;
    category: string;
    description: string;
    currency: string;
    estimated_minor: number;
    actual_minor: number | null;
    estimated_input: string;
    actual_input: string;
    exponent: number;
    manual_rate: string | null;
    rate_note: string | null;
}

export interface MapConfig {
    tile_url: string;
    attribution: string;
}

export interface Share {
    id: number;
    url: string;
    include_budget_totals: boolean;
    created_at: string;
    last_viewed_at: string | null;
}
