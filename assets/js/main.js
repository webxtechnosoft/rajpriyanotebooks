// Main Client-Side JavaScript for Rajpriya Notebooks

document.addEventListener('DOMContentLoaded', function() {
    // Global Delegated AJAX Add to Cart Handler (Prevents Page Reload & Redirects)
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form && form.classList.contains('add-to-cart-form')) {
            e.preventDefault();
            
            if (form.dataset.submitting === 'true') return;
            form.dataset.submitting = 'true';
            
            const submitBtn = form.querySelector('button[type="submit"], .js-add-to-cart-btn');
            const origBtnText = submitBtn ? submitBtn.innerHTML : '<i class="bi bi-bag-plus-fill fs-6"></i><span>Add to Cart</span>';
            
            const card = form.closest('.product-card') || form.closest('.col') || form.closest('.card') || document;
            const orderRows = card.querySelectorAll('.js-variant-order-row');

            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('ajax', '1');

            if (orderRows && orderRows.length > 0) {
                let itemCount = 0;
                orderRows.forEach((row) => {
                    const pid = row.getAttribute('data-product-id');
                    const ruling = row.getAttribute('data-ruling-type');
                    const qtyInput = row.querySelector('.js-order-qty-input');
                    const qty = qtyInput ? Math.max(1, parseInt(qtyInput.value || '1', 10)) : 1;

                    if (pid && ruling) {
                        formData.append('items[' + itemCount + '][product_id]', pid);
                        formData.append('items[' + itemCount + '][ruling_type]', ruling);
                        formData.append('items[' + itemCount + '][quantity]', qty);
                        itemCount++;
                    }
                });

                if (itemCount === 0) {
                    delete form.dataset.submitting;
                    alert('Please select at least one page count variant.');
                    return;
                }
            } else {
                // Ensure visible quantity is synced to the form hidden qty field before reading FormData
                const orderTypeInput = card.querySelector ? card.querySelector('.js-card-order-type') : null;
                const orderType = orderTypeInput ? orderTypeInput.value : 'Bundle';
                const visibleQtyInput = (orderType === 'Bundle') 
                    ? (card.querySelector ? card.querySelector('.js-bundle-qty-input') : null)
                    : (card.querySelector ? card.querySelector('.js-piece-qty-input') : null);
                const hiddenQtyField = card.querySelector ? card.querySelector('.js-card-qty-field') : null;
                if (visibleQtyInput && hiddenQtyField) {
                    const parsedQty = parseInt(visibleQtyInput.value, 10);
                    hiddenQtyField.value = (!isNaN(parsedQty) && parsedQty > 0) ? parsedQty : 1;
                }

                const rawFormData = new FormData(form);
                for (let pair of rawFormData.entries()) {
                    formData.append(pair[0], pair[1]);
                }
                if (!formData.has('ajax')) formData.append('ajax', '1');
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Adding...';
            }

            fetch('cart-action.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    // 1. Update all navbar cart counts without page reload
                    const cartBadges = document.querySelectorAll('.cart-badge-count, .js-cart-count');
                    cartBadges.forEach(badge => {
                        badge.textContent = data.cart_count;
                        badge.classList.add('badge-bounce');
                        setTimeout(() => badge.classList.remove('badge-bounce'), 500);
                    });

                    // 2. Temporarily show success state on submit button
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('btn-gold');
                        submitBtn.classList.add('btn-success');
                        submitBtn.innerHTML = '<i class="bi bi-check-circle-fill fs-6 me-1"></i> Added!';

                        setTimeout(() => {
                            submitBtn.classList.remove('btn-success');
                            submitBtn.classList.add('btn-gold');
                            submitBtn.innerHTML = origBtnText;
                        }, 2000);
                    }

                    // 3. Show unobtrusive toast notification
                    showToastNotification(data.message || 'Added to Cart!');
                } else {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = origBtnText;
                    }
                    alert(data ? data.message : 'Could not add to cart.');
                }
            })
            .catch(err => {
                console.error('Add to cart error:', err);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origBtnText;
                }
            })
            .finally(() => {
                delete form.dataset.submitting;
            });
        }
    });

    // Hero Full-Width Carousel Logic & Synchronization
    const heroCarouselEl = document.getElementById('homeHeroCarousel') || document.getElementById('heroFullWidthCarousel');
    if (heroCarouselEl && typeof bootstrap !== 'undefined') {
        const isHomeHero = heroCarouselEl.id === 'homeHeroCarousel';
        const carousel = new bootstrap.Carousel(heroCarouselEl, {
            interval: 4500,
            pause: isHomeHero ? false : 'hover',
            touch: true,
            wrap: true,
            ride: 'carousel'
        });

        // Explicitly start cycling
        carousel.cycle();

        const dots = heroCarouselEl.querySelectorAll('.hero-fw-indicators button, .home-hero-indicators button');

        // Function to update active dot
        function updateActiveDot(targetIndex) {
            dots.forEach((dot, index) => {
                if (index === targetIndex) {
                    dot.classList.add('active');
                    dot.setAttribute('aria-current', 'true');
                } else {
                    dot.classList.remove('active');
                    dot.removeAttribute('aria-current');
                }
            });
        }

        // Listen for carousel slide events (fires when slide transition completes)
        heroCarouselEl.addEventListener('slid.bs.carousel', function(e) {
            if (typeof e.to !== 'undefined') {
                updateActiveDot(e.to);
            }
        });

        // Listen for slide start event to update dot immediately for responsive feel
        heroCarouselEl.addEventListener('slide.bs.carousel', function(e) {
            if (typeof e.to !== 'undefined') {
                updateActiveDot(e.to);
            }
        });

        // Handle dot clicks explicitly to ensure immediate display and continuous auto-slide
        dots.forEach((dot, index) => {
            dot.addEventListener('click', function(e) {
                e.preventDefault();
                carousel.to(index);
                updateActiveDot(index);
                // Ensure auto sliding continues normally from this slide
                carousel.cycle();
            });
        });
    }

    // ==========================================================================
    // INTERACTIVE PRODUCT VARIANT BUTTON SELECTION
    // ==========================================================================
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-variant');
        if (!btn) return;
        
        const card = btn.closest('.product-card, .product-details-container, .col, .col-lg-7, .ps-lg-3');
        const container = card || document;
        
        // 1. Remove active class from sister buttons in the same variant group
        const group = btn.closest('.variant-buttons-group') || container;
        const siblingBtns = group.querySelectorAll('.btn-variant');
        siblingBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        // 2. Extract data attributes
        const id = btn.dataset.id;
        const title = btn.dataset.title;
        const price = btn.dataset.price;
        const oldPrice = btn.dataset.oldPrice;
        const meta = btn.dataset.meta;
        const sku = btn.dataset.sku;
        const image = btn.dataset.image;
        const stock = parseInt(btn.dataset.stock || '100', 10);
        
        // 3. Update hidden product_id in Add to Cart form
        const cartInputs = container.querySelectorAll('.js-variant-product-id, input[name="product_id"]');
        cartInputs.forEach(input => {
            if (id) input.value = id;
        });
        
        // 5. Update Product links
        if (id) {
            const links = container.querySelectorAll('.js-product-link, a[href^="product-details.php"]');
            links.forEach(l => {
                l.href = 'product-details.php?id=' + id;
            });
        }
        
        // 6. Update Price display
        const priceElem = container.querySelector('.js-product-price, .price-current, #displayedPrice');
        if (priceElem && price) {
            priceElem.textContent = price;
        }
        
        const oldPriceElem = container.querySelector('.js-product-old-price, .price-old');
        if (oldPriceElem) {
            if (oldPrice && oldPrice !== price) {
                oldPriceElem.textContent = oldPrice;
                oldPriceElem.classList.remove('d-none');
                oldPriceElem.style.display = 'inline';
            } else {
                oldPriceElem.classList.add('d-none');
                oldPriceElem.style.display = 'none';
            }
        }
        
        // 7. Update Meta & SKU text
        const metaElem = container.querySelector('.js-product-meta, .product-meta');
        if (metaElem && meta) {
            metaElem.textContent = meta;
        }
        
        const skuElem = container.querySelector('.js-product-sku, .product-sku');
        if (skuElem && sku) {
            skuElem.textContent = sku;
        }
        
        // 8. Update Image (with smooth transition)
        const imgElem = container.querySelector('.js-product-img, .product-img-box img, .img-fluid');
        if (imgElem && image) {
            const currentSrc = imgElem.getAttribute('src');
            if (currentSrc !== image) {
                imgElem.style.opacity = '0.3';
                setTimeout(() => {
                    imgElem.src = image;
                    imgElem.style.opacity = '1';
                }, 120);
            }
        }
        
        // 9. Update Stock & Add to Cart button state
        const cartBtn = container.querySelector('.js-add-to-cart-btn, button[type="submit"]');
        if (cartBtn) {
            cartBtn.disabled = false;
            cartBtn.innerHTML = '<i class="bi bi-bag-plus-fill me-1"></i> Add to Cart';
            cartBtn.classList.remove('btn-secondary');
            cartBtn.classList.add('btn-gold');
        }
    });
});

// Auto-fill the district headquarters pincode in address forms.
// District pincodes can represent more than one delivery location, so the
// customer can still edit the pincode when needed.
const tamilNaduDistrictPincodes = {
    'ariyalur': '621704',
    'chengalpattu': '603001',
    'chennai': '600001',
    'coimbatore': '641001',
    'cuddalore': '607001',
    'dharmapuri': '636701',
    'dindigul': '624001',
    'erode': '638001',
    'kallakurichi': '606202',
    'kancheepuram': '631501',
    'kanyakumari': '629001',
    'kanniyakumari': '629001',
    'karur': '639001',
    'krishnagiri': '635001',
    'madurai': '625001',
    'mayiladuthurai': '609001',
    'nagapattinam': '611001',
    'namakkal': '637001',
    'nilgiris': '643001',
    'ooty': '643001',
    'perambalur': '621212',
    'pudukkottai': '622001',
    'ramanathapuram': '623501',
    'ranipet': '632401',
    'salem': '636001',
    'sivaganga': '630561',
    'tenkasi': '627811',
    'thanjavur': '613001',
    'theni': '625531',
    'thoothukudi': '628001',
    'tiruchirappalli': '620001',
    'trichy': '620001',
    'tirunelveli': '627001',
    'tirupathur': '635601',
    'tiruppur': '641601',
    'tiruvallur': '602001',
    'tiruvannamalai': '606601',
    'tiruvarur': '610001',
    'vellore': '632001',
    'villupuram': '605602',
    'virudhunagar': '626001'
};

function normalizeDistrictName(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '');
}

document.querySelectorAll('input[name="district"]').forEach(function(field) {
    const form = field.closest('form') || document;
    const districtField = form.querySelector('input[name="district"]');
    const pincodeField = form.querySelector('input[name="pincode"]');
    if (!districtField || !pincodeField) return;

    const applyDistrictPincode = function() {
        const districtKey = normalizeDistrictName(districtField.value);
        const pincode = tamilNaduDistrictPincodes[districtKey];
        if (!pincode) return;

        if (!pincodeField.value || pincodeField.dataset.autoDistrictPincode === pincodeField.value) {
            pincodeField.value = pincode;
            pincodeField.dataset.autoDistrictPincode = pincode;
            pincodeField.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    districtField.addEventListener('input', applyDistrictPincode);
    districtField.addEventListener('change', applyDistrictPincode);
    districtField.addEventListener('blur', applyDistrictPincode);
    applyDistrictPincode();
});

// Toggle Pagination Menu (Req 2)
function togglePaginationMenu(btn) {
    if (!btn) return;
    const section = btn.closest('.pagination-section');
    if (!section) return;
    const controls = section.querySelector('.js-pagination-controls');
    const chevron = btn.querySelector('.js-toggle-chevron');
    if (controls) {
        controls.classList.toggle('d-none');
        if (chevron) {
            chevron.classList.toggle('bi-chevron-down');
            chevron.classList.toggle('bi-chevron-up');
        }
    }
}

// 1. Toggle Main Card "Page" Button (Initially page counts & varieties HIDDEN)
function toggleCardPagesMenu(btn, evt) {
    if (evt && evt.preventDefault) evt.preventDefault();
    const card = btn.closest('.product-card');
    if (!card) return;
    const container = card.querySelector('.js-card-pages-container');
    const chevron = btn.querySelector('.js-chevron-icon');
    if (container) {
        container.classList.toggle('d-none');
        if (chevron) {
            chevron.classList.toggle('bi-chevron-down');
            chevron.classList.toggle('bi-chevron-up');
        }
    }
}

// 2. Toggle Specific Page Count (Multi-Select Support)
function toggleSpecificPageCount(btn, pageKey, evt) {
    if (evt && evt.preventDefault) evt.preventDefault();
    const card = btn.closest('.product-card') || btn.closest('.col') || btn.closest('.card') || document;
    if (!card) return;

    btn.classList.toggle('active');
    btn.classList.toggle('selected');

    const panels = card.querySelectorAll('.js-pg-variety-panel');
    panels.forEach(panel => {
        if (panel.getAttribute('data-page') === pageKey) {
            if (btn.classList.contains('active')) {
                panel.classList.remove('d-none');
            } else {
                panel.classList.add('d-none');
            }
        }
    });
}

function selectSpecificPageCount(btn, pageKey, evt) {
    toggleSpecificPageCount(btn, pageKey, evt);
}

// 3. Toggle Specific Variety (Independent Quantity Rows & Multi-Variety Support)
function toggleSpecificVariety(btn, evt) {
    if (evt && evt.preventDefault) evt.preventDefault();
    const card = btn.closest('.product-card') || btn.closest('.col') || btn.closest('.card') || document;
    if (!card) return;

    btn.classList.toggle('active');
    btn.classList.toggle('selected');

    const panel = btn.closest('.js-pg-variety-panel');
    if (!panel) return;

    const ordersContainer = panel.querySelector('.js-variety-orders-container');
    if (!ordersContainer) return;

    const id = btn.dataset.id;
    const stock = parseInt(btn.dataset.stock || '0', 10);
    const price = btn.dataset.price;
    const image = btn.dataset.image;
    const variety = btn.dataset.variety;
    const page = btn.dataset.page;
    const rulingType = (page && variety) ? (page + ' - ' + variety) : (variety || 'Standard');

    let existingRow = null;
    const currentRows = ordersContainer.querySelectorAll('.js-variant-order-row');
    currentRows.forEach(row => {
        if (row.getAttribute('data-ruling-type') === rulingType) {
            existingRow = row;
        }
    });

    if (btn.classList.contains('active')) {
        if (!existingRow) {
            const rowDiv = document.createElement('div');
            rowDiv.className = 'js-variant-order-row variant-order-card p-2 rounded border bg-light shadow-xs d-flex flex-column gap-1.5 mt-2';
            rowDiv.style.cssText = 'width: 100%; box-sizing: border-box; overflow: hidden;';
            rowDiv.setAttribute('data-product-id', id);
            rowDiv.setAttribute('data-ruling-type', rulingType);
            rowDiv.setAttribute('data-stock', stock);
            rowDiv.setAttribute('data-price', price || '');

            const isAvailable = stock > 0;
            const stockBadgeHtml = isAvailable
                ? `<span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold fs-8"><i class="bi bi-check-circle-fill me-1"></i>Available Stock: ${stock}</span>`
                : `<span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold fs-8"><i class="bi bi-check-circle-fill me-1"></i>Available for order</span>`;

            rowDiv.innerHTML = `
                <div class="fw-bold fs-7 text-dark text-truncate mb-0" style="line-height: 1.2;">${rulingType}</div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="width: 100%;">
                    <div class="d-flex align-items-center me-auto">
                        ${stockBadgeHtml}
                    </div>
                    <div class="d-flex align-items-center gap-1.5 ms-auto" style="flex-shrink: 0;">
                        <span class="fs-7 fw-semibold text-secondary m-0 d-inline-flex align-items-center" style="font-size: 0.775rem; white-space: nowrap; line-height: 1;">Quantity:</span>
                        <div class="d-inline-flex align-items-center rounded border border-secondary-subtle bg-white overflow-hidden" style="height: 28px; box-sizing: border-box;">
                            <button class="btn btn-sm btn-light border-0 px-0 py-0 fw-bold h-100 d-flex align-items-center justify-content-center text-dark" type="button" style="width: 26px; min-width: 26px; font-size: 0.85rem; line-height: 1; border-radius: 0;" onclick="changeOrderRowQty(this, -1)">−</button>
                            <input type="number" class="form-control form-control-sm text-center fw-bold border-0 px-0 h-100 js-order-qty-input" value="1" min="1" max="999999" style="width: 32px; min-width: 32px; font-size: 0.8rem; line-height: 1; border-radius: 0; background: transparent; -moz-appearance: textfield; box-shadow: none;" onchange="validateOrderRowQty(this)">
                            <button class="btn btn-sm btn-light border-0 px-0 py-0 fw-bold h-100 d-flex align-items-center justify-content-center text-dark" type="button" style="width: 26px; min-width: 26px; font-size: 0.85rem; line-height: 1; border-radius: 0;" onclick="changeOrderRowQty(this, 1)">+</button>
                        </div>
                    </div>
                </div>
            `;
            ordersContainer.appendChild(rowDiv);
        }
    } else {
        if (existingRow) {
            existingRow.remove();
        }
    }

    if (image) {
        const imgElem = card.querySelector('.js-product-img');
        if (imgElem && imgElem.src !== image) {
            imgElem.src = image;
        }
    }
}

function selectSpecificVariety(btn, evt) {
    toggleSpecificVariety(btn, evt);
}

// 4. Change & Validate Independent Order Row Quantity Selector (- 1 +)
function changeOrderRowQty(btn, delta) {
    const wrap = btn.parentElement || btn.closest('.input-group') || btn.closest('div');
    if (!wrap) return;
    const input = wrap.querySelector('.js-order-qty-input') || wrap.querySelector('input[type="number"]');
    if (!input) return;
    const row = btn.closest('.js-variant-order-row');
    const maxStock = row ? parseInt(row.getAttribute('data-stock') || '100', 10) : 100;

    let val = parseInt(input.value || '1', 10) + delta;
    if (val < 1) val = 1;
    if (maxStock > 0 && val > maxStock) val = maxStock;
    input.value = val;
}

function validateOrderRowQty(input) {
    const row = input.closest('.js-variant-order-row');
    const maxStock = row ? parseInt(row.getAttribute('data-stock') || '100', 10) : 100;
    let val = parseInt(input.value || '1', 10);
    if (isNaN(val) || val < 1) val = 1;
    if (maxStock > 0 && val > maxStock) val = maxStock;
    input.value = val;
}

function changeVarietyQty(btn, delta) {
    changeOrderRowQty(btn, delta);
}

function validateVarietyQty(input) {
    validateOrderRowQty(input);
}

// Global Toast Notification Helper
function showToastNotification(msg) {
    let toastContainer = document.getElementById('global-toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'global-toast-container';
        toastContainer.style.cssText = 'position: fixed; bottom: 24px; right: 24px; z-index: 99999; display: flex; flex-direction: column; gap: 8px; pointer-events: none;';
        document.body.appendChild(toastContainer);
    }

    const toast = document.createElement('div');
    toast.className = 'toast-notification-item bg-navy text-white px-3.5 py-2.5 rounded-3 shadow-lg d-flex align-items-center gap-2 border border-warning-subtle';
    toast.style.cssText = 'pointer-events: auto; animation: toastIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; background-color: #17243A; color: #FFFFFF; font-size: 0.85rem; font-weight: 600; min-width: 220px;';
    toast.innerHTML = `<i class="bi bi-check-circle-fill text-warning fs-5"></i><span>${msg}</span>`;

    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}

// Quantity Memory per (Page + Variety + Order Type) combination
function getCardComboKey(card) {
    if (!card) return 'default';
    const activePageBtn = card.querySelector('.js-card-variety-btn.active');
    const activePageLabel = card.getAttribute('data-active-page') || (activePageBtn ? (activePageBtn.getAttribute('data-page-label') || activePageBtn.textContent.trim()) : '');

    const activeVarBtn = card.querySelector('.js-card-variety-box-btn.active');
    const activeVariety = activeVarBtn ? (activeVarBtn.getAttribute('data-variety') || activeVarBtn.textContent.trim()) : 'Standard';

    const activeOrderTypeInput = card.querySelector('.js-card-order-type');
    const activeOrderType = activeOrderTypeInput ? activeOrderTypeInput.value : 'Bundle';

    return (activePageLabel + '_' + activeVariety + '_' + activeOrderType).trim();
}

function saveCardQtyMemory(card, qty) {
    if (!card) return;
    if (!card._qtyMemory) card._qtyMemory = {};
    const key = getCardComboKey(card);
    card._qtyMemory[key] = qty;
}

function restoreCardQtyMemory(card) {
    if (!card) return;
    if (!card._qtyMemory) card._qtyMemory = {};
    const key = getCardComboKey(card);
    const rememberedQty = (card._qtyMemory[key] !== undefined) ? card._qtyMemory[key] : 1;

    const activeOrderTypeInput = card.querySelector('.js-card-order-type');
    const orderType = activeOrderTypeInput ? activeOrderTypeInput.value : 'Bundle';

    const qtyInput = (orderType === 'Bundle') ? card.querySelector('.js-bundle-qty-input') : card.querySelector('.js-piece-qty-input');
    if (qtyInput) {
        qtyInput.value = rememberedQty;
    }

    const hiddenQty = card.querySelector('.js-card-qty-field');
    if (hiddenQty) {
        hiddenQty.value = rememberedQty;
    }
}

// Wholesale Product Card Interactive Logic
function selectCardOrderType(btn, orderType) {
    const card = btn.closest('.js-product-card') || btn.closest('.product-card');
    if (!card) return;

    // Toggle button active state
    const orderBtns = card.querySelectorAll('.js-order-type-btn');
    orderBtns.forEach(b => {
        if (b.getAttribute('data-order-type') === orderType) {
            b.classList.add('active');
        } else {
            b.classList.remove('active');
        }
    });

    // Toggle quantity panels
    const bundlePanel = card.querySelector('.js-qty-panel-bundle');
    const piecePanel = card.querySelector('.js-qty-panel-piece');
    const orderTypeInput = card.querySelector('.js-card-order-type');

    if (orderTypeInput) orderTypeInput.value = orderType;

    if (orderType === 'Bundle') {
        if (bundlePanel) bundlePanel.classList.remove('d-none');
        if (piecePanel) piecePanel.classList.add('d-none');
    } else {
        if (bundlePanel) bundlePanel.classList.add('d-none');
        if (piecePanel) piecePanel.classList.remove('d-none');
    }

    restoreCardQtyMemory(card);
}

function adjustCardQty(btn, delta, type) {
    const wrap = btn.closest('.quantity-section') || btn.parentElement;
    if (!wrap) return;
    const input = type === 'Bundle' ? wrap.querySelector('.js-bundle-qty-input') : wrap.querySelector('.js-piece-qty-input');
    if (!input) return;

    let val = parseInt(input.value || '1', 10) + delta;
    const min = parseInt(input.getAttribute('min') || '1', 10);
    const max = parseInt(input.getAttribute('max') || '9999', 10);

    if (val < min) val = min;
    if (max > 0 && val > max) val = max;

    input.value = val;
    syncCardFormQty(input, type);
}

function syncCardFormQty(input, type) {
    const card = input.closest('.js-product-card') || input.closest('.product-card');
    if (!card) return;

    let val = parseInt(input.value || '1', 10);
    if (isNaN(val) || val < 1) val = 1;
    input.value = val;

    const currentOrderType = card.querySelector('.js-card-order-type')?.value || 'Bundle';
    if (currentOrderType === type) {
        const hiddenQty = card.querySelector('.js-card-qty-field');
        if (hiddenQty) hiddenQty.value = val;
        saveCardQtyMemory(card, val);
    }

    if (type === 'Bundle') {
        updateCardBundleCalculations(card);
    }
}

function updateCardBundleCalculations(card) {
    if (!card) return;
    const bundleInput = card.querySelector('.js-bundle-qty-input');
    const bundles = parseInt(bundleInput?.value || '1', 10) || 1;

    let ppb = parseInt(card.getAttribute('data-ppb') || '1', 10);
    const ppbEl = card.querySelector('.js-card-ppb-display');
    if (ppbEl) {
        const textVal = parseInt(ppbEl.textContent.replace(/[^0-9]/g, ''), 10);
        if (textVal > 0) ppb = textVal;
    }
    const stdQtySpan = card.querySelector('.js-card-std-qty');
    if (ppb <= 1 && stdQtySpan) {
        const match = stdQtySpan.textContent.match(/(\d+)/);
        if (match) ppb = parseInt(match[1], 10);
    }
    ppb = Math.max(1, ppb);

    const totalPieces = ppb * bundles;

    const ppbDisplay = card.querySelector('.js-card-ppb-display');
    if (ppbDisplay) ppbDisplay.textContent = ppb;

    const bdlDisplay = card.querySelector('.js-card-bundles-display');
    if (bdlDisplay) bdlDisplay.textContent = bundles;

    const totalPcsDisplay = card.querySelector('.js-card-total-pcs-display');
    if (totalPcsDisplay) totalPcsDisplay.textContent = totalPieces.toLocaleString() + ' Pieces';

    const totalBdlDisplay = card.querySelector('.js-card-total-bdl-display');
    if (totalBdlDisplay) totalBdlDisplay.textContent = bundles;

    const formulaDisplay = card.querySelector('.js-card-calc-formula');
    if (formulaDisplay) {
        formulaDisplay.innerHTML = `Formula: ${ppb} Pcs &times; ${bundles} Bundle${bundles > 1 ? 's' : ''} = ${totalPieces.toLocaleString()} Pieces`;
    }
}

function parseVarietyItems(varietyStr) {
    if (!varietyStr) return ['Standard'];
    if (typeof varietyStr === 'object' && Array.isArray(varietyStr)) return varietyStr;

    let str = String(varietyStr).trim();
    if (str.toLowerCase() === 'both' || str.toLowerCase() === 'unruled & ruled' || str.toLowerCase() === 'unruled and ruled') {
        return ['Unruled', 'Ruled'];
    }

    str = str.replace(/Unruled\s*(?:&|and|\/)\s*Ruled/gi, 'Unruled, Ruled');

    const parts = str.split(/[,|\/]+/).map(s => s.trim()).filter(Boolean);
    return parts.length > 0 ? parts : [str];
}

function selectCardPageCount(btn, pageLabel) {
    const card = btn.closest('.js-product-card') || btn.closest('.product-card');
    if (!card) return;

    const pageMapStr = card.getAttribute('data-page-map');
    if (!pageMapStr) return;

    try {
        const pageMap = JSON.parse(pageMapStr);
        const pageData = pageMap[pageLabel];
        if (!pageData) return;

        // 1. Highlight active Page Count button
        const chips = card.querySelectorAll('.js-card-variety-btn');
        chips.forEach(c => {
            const btnLabel = c.getAttribute('data-page-label') || c.textContent.trim();
            if (btnLabel === pageLabel) {
                c.classList.add('btn-navy', 'text-white', 'fw-bold', 'active');
                c.classList.remove('btn-outline-secondary');
            } else {
                c.classList.remove('btn-navy', 'text-white', 'fw-bold', 'active');
                c.classList.add('btn-outline-secondary');
            }
        });

        // 2. Set active page attribute on card container
        card.setAttribute('data-active-page', pageLabel);

        // 3. Update Title of Variety Drawer
        const pageTitleElem = card.querySelector('.js-card-active-page-title');
        if (pageTitleElem) pageTitleElem.textContent = pageLabel;

        // 4. Render Variety Box Cards for this Page Count
        const boxesWrap = card.querySelector('.js-card-variety-boxes');
        const varieties = pageData.varieties || {};
        const varietyNames = Object.keys(varieties);

        // Intelligently select first in-stock variety (bundles first, then pieces)
        let chosenVarName = null;
        for (const vN of varietyNames) {
            if (varieties[vN] && (varieties[vN].bundles || 0) > 0) {
                chosenVarName = vN;
                break;
            }
        }
        if (!chosenVarName) {
            for (const vN of varietyNames) {
                if (varieties[vN] && (varieties[vN].stock || 0) > 0) {
                    chosenVarName = vN;
                    break;
                }
            }
        }
        if (!chosenVarName && varietyNames.length > 0) {
            chosenVarName = varietyNames[0];
        }

        if (boxesWrap && varietyNames.length > 0) {
            boxesWrap.innerHTML = '';
            varietyNames.forEach((vName) => {
                const varietyButton = document.createElement('button');
                varietyButton.type = 'button';
                varietyButton.className = `variety-box-card js-card-variety-box-btn ${vName === chosenVarName ? 'active' : ''}`;
                varietyButton.setAttribute('data-variety', vName);
                varietyButton.textContent = vName;
                varietyButton.addEventListener('click', function() {
                    selectCardVarietyBox(varietyButton, vName);
                });
                boxesWrap.appendChild(varietyButton);
            });
        }

        // 5. Trigger selection of chosen variety for this page count
        if (chosenVarName && varieties[chosenVarName]) {
            updateCardVariantDetails(card, pageLabel, chosenVarName, varieties[chosenVarName]);
        }
    } catch (e) {
        console.error('Error selecting page count:', e);
    }
}

function selectCardVarietyBox(btn, varietyName) {
    const card = btn.closest('.js-product-card') || btn.closest('.product-card');
    if (!card) return;

    // Highlight active variety box button
    const boxBtns = card.querySelectorAll('.js-card-variety-box-btn');
    boxBtns.forEach(b => {
        const bVar = b.getAttribute('data-variety') || b.textContent.trim();
        if (bVar === varietyName) {
            b.classList.add('active');
        } else {
            b.classList.remove('active');
        }
    });

    const activePageBtn = card.querySelector('.js-card-variety-btn.active');
    const activePageLabel = card.getAttribute('data-active-page') || (activePageBtn ? (activePageBtn.getAttribute('data-page-label') || activePageBtn.textContent.trim()) : '');
    const pageMapStr = card.getAttribute('data-page-map');
    if (!pageMapStr || !activePageLabel) return;

    try {
        const pageMap = JSON.parse(pageMapStr);
        const pageData = pageMap[activePageLabel];
        if (pageData && pageData.varieties && pageData.varieties[varietyName]) {
            updateCardVariantDetails(card, activePageLabel, varietyName, pageData.varieties[varietyName]);
        }
    } catch (e) {
        console.error('Error selecting variety box:', e);
    }
}

function updateCardVariantDetails(card, pageLabel, varietyName, vData) {
    if (!vData) return;

    // 0. Update Product Title and Image Alt/Title to match active variant name
    if (vData.name) {
        const titleElem = card.querySelector('.js-product-title');
        if (titleElem) {
            titleElem.textContent = vData.name;
            titleElem.setAttribute('title', vData.name);
        }
        const linkElems = card.querySelectorAll('.js-product-link');
        linkElems.forEach(l => {
            l.setAttribute('title', vData.name);
        });
        const imgElem = card.querySelector('.js-product-img');
        if (imgElem) {
            imgElem.setAttribute('alt', vData.name);
        }
    }

    // 1. Update Hidden Form Fields
    const pidField = card.querySelector('.js-card-product-id');
    if (pidField) pidField.value = vData.id;

    const rulingField = card.querySelector('.js-card-ruling-type');
    if (rulingField) {
        rulingField.value = (varietyName && varietyName !== 'Standard') ? (pageLabel + ' - ' + varietyName) : pageLabel;
    }
    const varietyIdField = card.querySelector('.js-card-variety-id');
    if (varietyIdField) varietyIdField.value = vData.variety_id || '';

    // 2. Update Brand, Size, GSM Badges
    const brandElem = card.querySelector('.js-product-brand');
    if (brandElem) brandElem.innerHTML = `<i class="bi bi-patch-check-fill text-gold me-1"></i>${vData.brand}`;

    const sizeElem = card.querySelector('.js-product-size-badge');
    if (sizeElem) sizeElem.textContent = vData.size;

    const gsmElem = card.querySelector('.js-product-gsm-badge');
    if (gsmElem && vData.gsm) gsmElem.textContent = `GSM: ${vData.gsm} GSM`;

    // 3. Update Image
    const imgElem = card.querySelector('.js-product-img');
    if (imgElem && vData.image) imgElem.src = vData.image;

    // 4. Update Stock Display
    const stockDisplay = card.querySelector('.js-card-stock-display');
    if (stockDisplay) {
        if (vData.stock > 0) {
            stockDisplay.className = 'badge bg-warning-subtle text-dark border border-warning-subtle fw-bold px-2.5 py-1 js-card-stock-display';
            stockDisplay.textContent = `In Stock (${vData.stock} Pcs)`;
        } else {
            stockDisplay.className = 'badge bg-success-subtle text-success border border-success-subtle fw-bold px-2.5 py-1 js-card-stock-display';
            stockDisplay.textContent = 'Available for order';
        }
    }

    const availPieces = card.querySelector('.js-card-available-pieces');
    if (availPieces) availPieces.textContent = vData.stock;

    const stdQtyElem = card.querySelector('.js-card-std-qty');
    if (stdQtyElem) stdQtyElem.textContent = `${vData.std_qty} Pieces / Bundle`;

    card.setAttribute('data-ppb', vData.std_qty || '1');
    const ppbDisplay = card.querySelector('.js-card-ppb-display');
    if (ppbDisplay) ppbDisplay.textContent = vData.std_qty || '1';
    updateCardBundleCalculations(card);

    // Update Quantity Inputs Max limit
    const bundleInput = card.querySelector('.js-bundle-qty-input');
    if (bundleInput) bundleInput.setAttribute('max', '999999');

    const pieceInput = card.querySelector('.js-piece-qty-input');
    if (pieceInput) pieceInput.setAttribute('max', '999999');

    // 5. Update Pricing Box
    const sellerElem = card.querySelector('.js-card-seller-price');
    if (sellerElem) sellerElem.textContent = vData.seller_price;

    const wholesaleElem = card.querySelector('.js-card-wholesale-price');
    if (wholesaleElem) wholesaleElem.textContent = vData.wholesale_price;

    // 6. Update Add to Cart Button state & auto-adjust order type
    const activeOrderTypeInput = card.querySelector('.js-card-order-type');
    let currentOrderType = activeOrderTypeInput ? activeOrderTypeInput.value : 'Bundle';
    if (currentOrderType === 'Bundle' && vData.bundles <= 0 && vData.stock > 0) {
        const pieceBtn = card.querySelector('.js-order-type-btn[data-order-type="Single Piece"]');
        if (pieceBtn) selectCardOrderType(pieceBtn, 'Single Piece');
        currentOrderType = 'Single Piece';
    } else if (currentOrderType === 'Single Piece' && vData.stock <= 0 && vData.bundles > 0) {
        const bundleBtn = card.querySelector('.js-order-type-btn[data-order-type="Bundle"]');
        if (bundleBtn) selectCardOrderType(bundleBtn, 'Bundle');
        currentOrderType = 'Bundle';
    }

    // 8. Update Product details page Links
    const links = card.querySelectorAll('.js-product-link, a[href^="product-details.php"]');
    links.forEach(l => {
        l.href = 'product-details.php?id=' + vData.id;
    });

    // 9. Restore remembered quantity for this variety & combination
    restoreCardQtyMemory(card);
}

function selectCardVariety(btn, variantId) {
    const card = btn.closest('.js-product-card') || btn.closest('.product-card');
    if (!card) return;
    const pageLabel = btn.getAttribute('data-page-label') || btn.textContent.trim();
    if (pageLabel) {
        selectCardPageCount(btn, pageLabel);
    }
}

// Expose card functions globally on window
window.selectCardPageCount = selectCardPageCount;
window.selectCardVarietyBox = selectCardVarietyBox;
window.selectCardVariety = selectCardVariety;
window.updateCardVariantDetails = updateCardVariantDetails;
window.selectCardOrderType = selectCardOrderType;
window.adjustCardQty = adjustCardQty;
window.syncCardFormQty = syncCardFormQty;
