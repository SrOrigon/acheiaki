/**
 * Fallback local (somente dev sem PostgreSQL).
 * Em produção Railway os dados vêm de get_public_store.php.
 */
window.APP_CONFIG = window.APP_CONFIG || {
    TENANT_SLUG: 'default',
    NOME_LOJA: 'AcheIAki',
    SLOGAN: 'O Marketplace Inteligente da sua Região',
    WHATSAPP_NUMBER: '',
    IDENTIDADE: {
        LOGO: 'assets/images/placeholder-logo.svg',
        FAVICON: 'assets/images/placeholder-logo.svg',
        HERO: 'assets/images/placeholder-hero.svg',
        CORES: { primaria: '#f97136', secundaria: '#1f2937', fundo: '#f9fafb' }
    }
};