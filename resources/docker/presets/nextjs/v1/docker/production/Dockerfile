FROM node:22-alpine AS dependencies

WORKDIR /app
COPY package.json package-lock.json* yarn.lock* pnpm-lock.yaml* ./
RUN corepack enable \
    && if [ -f pnpm-lock.yaml ]; then pnpm install --frozen-lockfile; \
       elif [ -f yarn.lock ]; then yarn install --immutable; \
       elif [ -f package-lock.json ]; then npm ci; \
       else npm install; fi

FROM node:22-alpine AS builder

WORKDIR /app
COPY --from=dependencies /app/node_modules ./node_modules
COPY . .
RUN mkdir -p public \
    && corepack enable \
    && if [ -f pnpm-lock.yaml ]; then pnpm run build; \
       elif [ -f yarn.lock ]; then yarn build; \
       else npm run build; fi

FROM node:22-alpine AS production

ENV NODE_ENV=production
ENV HOSTNAME=0.0.0.0
ENV PORT=3000
WORKDIR /app
RUN addgroup --system --gid 1001 nodejs \
    && adduser --system --uid 1001 nextjs
COPY --from=builder --chown=nextjs:nodejs /app/package.json ./package.json
COPY --from=builder --chown=nextjs:nodejs /app/node_modules ./node_modules
COPY --from=builder --chown=nextjs:nodejs /app/.next ./.next
COPY --from=builder --chown=nextjs:nodejs /app/public ./public
USER nextjs
EXPOSE 3000
CMD ["node_modules/.bin/next", "start"]
