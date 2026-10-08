export const controlHeights = {
  sm: "h-9",
  md: "h-10",
  lg: "h-11",
} as const;

export type ControlSize = keyof typeof controlHeights;

export const pickerTriggerStyles =
  "group flex w-full items-center justify-between gap-2 rounded-md border border-input bg-card px-3.5 text-left text-sm text-foreground outline-none transition-[border-color,box-shadow] duration-200 ease-smooth hover:border-muted-foreground/40 focus-visible:border-ring focus-visible:ring-4 focus-visible:ring-ring/15 data-[placeholder]:text-subtle-foreground data-[state=open]:border-ring data-[state=open]:ring-4 data-[state=open]:ring-ring/15 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-danger aria-[invalid=true]:focus-visible:ring-danger/15";

export const pickerContentStyles =
  "z-50 animate-pop-in overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-popover";