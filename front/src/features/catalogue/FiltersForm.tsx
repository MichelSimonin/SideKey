import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { TextField } from '../../components/ui/TextField'
import { DEFAULT_FILTERS, activeFilterCount, normalizePrice } from './filtersUrl'
import { PEGI_VALUES, SORTS, type CatalogueFilters, type FilterValues, type Sort } from './types'

/**
 * Filtres du catalogue (E02, US-02, US-03, US-05). Chaque groupe est un `fieldset` avec sa `legend` (RGAA 11.5),
 * toutes les commandes ont une étiquette visible. Les critères sont appliqués à la validation du formulaire, puis
 * reflétés dans l'URL par la page ; l'API revalide chaque valeur (liste blanche du tri, formats, bornes).
 */
export function FiltersForm({
  filters,
  values,
  onApply,
  onReset,
}: {
  filters: CatalogueFilters
  /** null tant que les valeurs des filtres se chargent. */
  values: FilterValues | null
  onApply: (filters: CatalogueFilters) => void
  onReset: () => void
}) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState(filters)

  function toggle(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((item) => item !== value) : [...list, value]
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    onApply({
      ...draft,
      q: draft.q.trim(),
      prixMin: normalizePrice(draft.prixMin),
      prixMax: normalizePrice(draft.prixMax),
      page: 1,
    })
  }

  return (
    <form role="search" className="filters" onSubmit={submit} aria-label={t('catalogue.filters.legend')}>
      <TextField
        id="filter-q"
        name="q"
        type="search"
        label={t('catalogue.filters.search')}
        value={draft.q}
        onChange={(event) => setDraft({ ...draft, q: event.target.value })}
        maxLength={100}
        autoComplete="off"
      />

      {values === null ? (
        <p role="status">{t('catalogue.filters.loading')}</p>
      ) : (
        <>
          <fieldset>
            <legend>{t('catalogue.filters.platforms')}</legend>
            {values.plateformes.map((platform) => (
              <label key={platform.code} className="checkbox">
                <input
                  type="checkbox"
                  name="plateforme"
                  value={platform.code}
                  checked={draft.plateformes.includes(platform.code)}
                  onChange={() => setDraft({ ...draft, plateformes: toggle(draft.plateformes, platform.code) })}
                />
                {platform.libelle}
              </label>
            ))}
          </fieldset>

          <fieldset>
            <legend>{t('catalogue.filters.genres')}</legend>
            {values.genres.map((genre) => (
              <label key={genre.slug} className="checkbox">
                <input
                  type="checkbox"
                  name="genre"
                  value={genre.slug}
                  checked={draft.genres.includes(genre.slug)}
                  onChange={() => setDraft({ ...draft, genres: toggle(draft.genres, genre.slug) })}
                />
                {genre.libelle}
              </label>
            ))}
          </fieldset>
        </>
      )}

      <fieldset>
        <legend>{t('catalogue.filters.price')}</legend>
        <div className="price-range">
          <TextField
            id="filter-price-min"
            name="prix_min"
            label={t('catalogue.filters.priceMin')}
            inputMode="decimal"
            autoComplete="off"
            value={draft.prixMin}
            onChange={(event) => setDraft({ ...draft, prixMin: event.target.value })}
            maxLength={9}
          />
          <TextField
            id="filter-price-max"
            name="prix_max"
            label={t('catalogue.filters.priceMax')}
            inputMode="decimal"
            autoComplete="off"
            value={draft.prixMax}
            onChange={(event) => setDraft({ ...draft, prixMax: event.target.value })}
            maxLength={9}
          />
        </div>
      </fieldset>

      <div className="field">
        <label htmlFor="filter-pegi">{t('catalogue.filters.pegi')}</label>
        <select
          id="filter-pegi"
          name="pegi"
          value={draft.pegi ?? ''}
          onChange={(event) => setDraft({ ...draft, pegi: event.target.value === '' ? null : Number(event.target.value) })}
        >
          <option value="">{t('catalogue.filters.pegiAll')}</option>
          {PEGI_VALUES.map((age) => (
            <option key={age} value={age}>
              {t('catalogue.filters.pegiOption', { age })}
            </option>
          ))}
        </select>
      </div>

      <div className="field">
        <label htmlFor="filter-sort">{t('catalogue.filters.sort')}</label>
        <select
          id="filter-sort"
          name="tri"
          value={draft.tri}
          onChange={(event) => setDraft({ ...draft, tri: event.target.value as Sort })}
        >
          {SORTS.map((sort) => (
            <option key={sort} value={sort}>
              {t(`catalogue.sorts.${sort}`)}
            </option>
          ))}
        </select>
      </div>

      <div className="filter-actions">
        <button type="submit" className="btn">
          {t('catalogue.filters.apply')}
        </button>
        {activeFilterCount(filters) > 0 || filters.tri !== DEFAULT_FILTERS.tri ? (
          <button
            type="button"
            className="link-button"
            onClick={() => {
              setDraft(DEFAULT_FILTERS)
              onReset()
            }}
          >
            {t('catalogue.filters.reset')}
          </button>
        ) : null}
      </div>
    </form>
  )
}
