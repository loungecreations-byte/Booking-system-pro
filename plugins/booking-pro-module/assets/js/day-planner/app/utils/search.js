const NORMALIZE_DIACRITICS_REGEX = /[\u0300-\u036f]/g;
const NON_ALPHANUMERIC_REGEX = /[^a-z0-9]+/g;

function pushTokensFrom(value, bucket) {
  const normalized = normalizeText(value);
  if (!normalized) {
    return;
  }
  normalized.split(" ").forEach((token) => {
    if (token) {
      bucket.add(token);
    }
  });
}

export function normalizeText(value) {
  if (typeof value !== "string") {
    return "";
  }

  try {
    return value
      .normalize("NFD")
      .replace(NORMALIZE_DIACRITICS_REGEX, "")
      .toLowerCase()
      .replace(NON_ALPHANUMERIC_REGEX, " ")
      .trim();
  } catch (error) {
    const fallback = String(value)
      .toLowerCase()
      .replace(NON_ALPHANUMERIC_REGEX, " ")
      .trim();
    return fallback;
  }
}

export function prepareSearchQuery(rawQuery) {
  if (typeof rawQuery !== "string") {
    return { raw: "", normalized: "", tokens: [] };
  }

  const normalized = normalizeText(rawQuery);
  if (normalized === "") {
    return { raw: rawQuery, normalized: "", tokens: [] };
  }

  const uniqueTokens = Array.from(
    new Set(
      normalized
        .split(" ")
        .map((token) => token.trim())
        .filter(Boolean)
    )
  );

  return {
    raw: rawQuery,
    normalized,
    tokens: uniqueTokens,
  };
}

export function getProductCategoryTokens(product) {
  if (!product || typeof product !== "object") {
    return [];
  }

  const tokens = new Set();

  const register = (candidate) => {
    if (typeof candidate !== "string") {
      return;
    }
    const cleaned = candidate.trim().toLowerCase();
    if (cleaned !== "") {
      tokens.add(cleaned);
    }
  };

  const categorySlugs = Array.isArray(product.category_slugs) ? product.category_slugs : [];
  categorySlugs.forEach(register);

  const categories = Array.isArray(product.categories) ? product.categories : [];
  categories.forEach((category) => {
    if (typeof category === "string") {
      register(category);
      return;
    }
    if (category && typeof category === "object") {
      if (typeof category.slug === "string") {
        register(category.slug);
      } else if (typeof category.name === "string") {
        register(category.name);
      }
    }
  });

  return Array.from(tokens);
}

export function createSearchEntry(product) {
  const normalizedName = normalizeText(product?.name ?? "");

  const tokenBucket = new Set();
  pushTokensFrom(product?.name, tokenBucket);
  pushTokensFrom(product?.slug, tokenBucket);

  if (Array.isArray(product?.tags)) {
    product.tags.forEach((tag) => pushTokensFrom(tag, tokenBucket));
  }

  if (typeof product?.description === "string") {
    pushTokensFrom(product.description, tokenBucket);
  }

  const categoryTokens = getProductCategoryTokens(product);
  categoryTokens.forEach((token) => pushTokensFrom(token, tokenBucket));

  return {
    product,
    normalizedName,
    tokens: Array.from(tokenBucket),
    categoryTokens,
  };
}

export function createSearchIndex(products) {
  return (products || []).map((product) => createSearchEntry(product));
}

export function evaluateSearchEntry(entry, query) {
  if (!entry) {
    return { matches: false, score: 0 };
  }

  if (!query || query.tokens.length === 0) {
    return { matches: true, score: 0 };
  }

  const { normalized, tokens } = query;
  let score = 0;
  let matchedTokens = 0;

  if (normalized && entry.normalizedName.includes(normalized)) {
    score += 6;
  }

  tokens.forEach((token) => {
    let tokenScore = 0;

    entry.tokens.forEach((candidate) => {
      if (candidate === token) {
        tokenScore = Math.max(tokenScore, 4);
      } else if (candidate.startsWith(token)) {
        tokenScore = Math.max(tokenScore, 3);
      } else if (candidate.includes(token)) {
        tokenScore = Math.max(tokenScore, 1);
      }
    });

    entry.categoryTokens.forEach((categoryToken) => {
      if (categoryToken === token) {
        tokenScore = Math.max(tokenScore, 3);
      } else if (categoryToken.startsWith(token)) {
        tokenScore = Math.max(tokenScore, 2);
      }
    });

    if (tokenScore > 0) {
      matchedTokens += 1;
      score += tokenScore;
    }
  });

  if (matchedTokens < tokens.length) {
    return { matches: false, score: 0 };
  }

  return { matches: true, score };
}
