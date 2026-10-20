export {};

declare global {
    interface RouteHelper {
        current(name?: string, params?: unknown): boolean | string | undefined;
    }
    function route(): RouteHelper;
    function route(name: string, params?: string | number | Record<string, unknown>, absolute?: boolean): string;
}

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}
