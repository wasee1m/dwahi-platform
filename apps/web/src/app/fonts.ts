import { Alexandria, IBM_Plex_Mono, IBM_Plex_Sans, IBM_Plex_Sans_Arabic } from "next/font/google";

// خطوط الهوية (01 §9.1): Alexandria للعناوين، و IBM Plex للنصوص والأرقام. مشتركة بين إطاري الموقع والإدارة.
const alexandria = Alexandria({ subsets: ["arabic", "latin"], weight: ["700", "800"], variable: "--font-alexandria", display: "swap" });
const plexArabic = IBM_Plex_Sans_Arabic({ subsets: ["arabic"], weight: ["400", "600", "700"], variable: "--font-plex-arabic", display: "swap" });
const plex = IBM_Plex_Sans({ subsets: ["latin"], weight: ["400", "600", "700"], variable: "--font-plex", display: "swap" });
const plexMono = IBM_Plex_Mono({ subsets: ["latin"], weight: ["400", "500"], variable: "--font-plex-mono", display: "swap" });

export const fontVariables = `${alexandria.variable} ${plexArabic.variable} ${plex.variable} ${plexMono.variable}`;
