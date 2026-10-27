// Typical client code against types generated from the spec of the dev site
// (`npm run client:check`). It must compile: a schema that is too loose or too
// strict for real responses breaks it, which response validation alone can't
// catch.
import type { components } from './generated/openapi';

type Schemas = components['schemas'];

// #265: a check column is sent as 0/1, not as "0"/"1".
export const isTopNews = (news: Schemas['NewsRead']): boolean => news.istopnews === 1;

// #264: ImageProcessor writes alt text, copyright and crop variants.
export const altText = (news: Schemas['NewsRead']): string | null | undefined => news.fal_media?.[0]?.metadata?.alternative;
export const copyright = (news: Schemas['NewsRead']): string | null | undefined => news.fal_media?.[0]?.metadata?.copyright;

// #261: the last page has no next link.
type View = NonNullable<Schemas['NewsCollection']['hydra:view']>;
export const lastPage: View['hydra:next'] = null;

// #261: hydra:search describes the filters.
export const searchTemplate = (page: Schemas['ArticleCollection']): string | undefined => page['hydra:search']?.['hydra:template'];

// #265: an empty datetime is null.
export const noDate: Schemas['NewsRead']['datetime'] = null;
