 class DentalChart {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.selectedTooth = null;
        this.selectedTeeth = new Set();
        this.selectedSegments = new Map(); // Map to store selected segments for each tooth
        this.treatments = options.treatments || {};
        this.isMultiSelect = options.multiSelect || false;
        this.onToothSelect = options.onToothSelect || (() => {});
        this.notationSystem = options.notationSystem || 'custom'; // 'universal', 'palmer', 'fdi', 'iso', 'custom'
        this.segmentMode = options.segmentMode || false; // Enable segment-level selection
        
        // Dental notation systems mapping
        this.notationSystems = {
            universal: this.createUniversalSystem(),
            palmer: this.createPalmerSystem(),
            fdi: this.createFDISystem(),
            iso: this.createISOSystem(),
            custom: this.createCustomSystem()
        };
        
        this.adultTeeth = this.notationSystems[this.notationSystem];
        
        this.treatmentColors = {
            'healthy': '#4ade80',
            'caries': '#ef4444',
            'filling': '#3b82f6',
            'crown': '#f59e0b',
            'root_canal': '#8b5cf6',
            'extraction': '#6b7280',
            'implant': '#06b6d4',
            'bridge': '#f97316',
            'missing': '#dc2626'
        };
        
        // Tooth type definitions for SVG paths (now using rectangles for easier segmentation)
        this.toothTypes = {
            'incisor': { width: 24, height: 16 },
            'canine': { width: 20, height: 20 },
            'premolar': { width: 28, height: 16 },
            'molar': { width: 32, height: 18 }
        };
        
        // Dental surface/segment names
        this.toothSegments = {
            'mesial': { name: 'Mesial', arabicName: 'أنسي', position: 'left', color: '#e3f2fd' },
            'distal': { name: 'Distal', arabicName: 'وحشي', position: 'right', color: '#f3e5f5' },
            'buccal': { name: 'Buccal', arabicName: 'دهليزي', position: 'top', color: '#e8f5e8' },
            'lingual': { name: 'Lingual', arabicName: 'لساني', position: 'bottom', color: '#fff3e0' }
        };
        
        this.init();
    }
    
    init() {
        this.createNotationSelector();
        this.createChart();
        this.attachEventListeners();
    }
    
    createUniversalSystem() {
        return {
            // Upper jaw (Maxilla) - Right to Left
            1: { position: { x: 50, y: 50 }, name: 'Third Molar', quadrant: 1, type: 'molar', arabicName: 'الضرس الثالث' },
            2: { position: { x: 90, y: 45 }, name: 'Second Molar', quadrant: 1, type: 'molar', arabicName: 'الضرس الثاني' },
            3: { position: { x: 130, y: 40 }, name: 'First Molar', quadrant: 1, type: 'molar', arabicName: 'الضرس الأول' },
            4: { position: { x: 170, y: 35 }, name: 'Second Premolar', quadrant: 1, type: 'premolar', arabicName: 'الضاحك الثاني' },
            5: { position: { x: 210, y: 30 }, name: 'First Premolar', quadrant: 1, type: 'premolar', arabicName: 'الضاحك الأول' },
            6: { position: { x: 250, y: 25 }, name: 'Canine', quadrant: 1, type: 'canine', arabicName: 'الناب' },
            7: { position: { x: 290, y: 20 }, name: 'Lateral Incisor', quadrant: 1, type: 'incisor', arabicName: 'القاطع الجانبي' },
            8: { position: { x: 330, y: 15 }, name: 'Central Incisor', quadrant: 1, type: 'incisor', arabicName: 'القاطع المركزي' },
            
            9: { position: { x: 370, y: 15 }, name: 'Central Incisor', quadrant: 2, type: 'incisor', arabicName: 'القاطع المركزي' },
            10: { position: { x: 410, y: 20 }, name: 'Lateral Incisor', quadrant: 2, type: 'incisor', arabicName: 'القاطع الجانبي' },
            11: { position: { x: 450, y: 25 }, name: 'Canine', quadrant: 2, type: 'canine', arabicName: 'الناب' },
            12: { position: { x: 490, y: 30 }, name: 'First Premolar', quadrant: 2, type: 'premolar', arabicName: 'الضاحك الأول' },
            13: { position: { x: 530, y: 35 }, name: 'Second Premolar', quadrant: 2, type: 'premolar', arabicName: 'الضاحك الثاني' },
            14: { position: { x: 570, y: 40 }, name: 'First Molar', quadrant: 2, type: 'molar', arabicName: 'الضرس الأول' },
            15: { position: { x: 610, y: 45 }, name: 'Second Molar', quadrant: 2, type: 'molar', arabicName: 'الضرس الثاني' },
            16: { position: { x: 650, y: 50 }, name: 'Third Molar', quadrant: 2, type: 'molar', arabicName: 'الضرس الثالث' },
            
            // Lower jaw (Mandible) - Right to Left
            32: { position: { x: 50, y: 200 }, name: 'Third Molar', quadrant: 4, type: 'molar', arabicName: 'الضرس الثالث' },
            31: { position: { x: 90, y: 195 }, name: 'Second Molar', quadrant: 4, type: 'molar', arabicName: 'الضرس الثاني' },
            30: { position: { x: 130, y: 190 }, name: 'First Molar', quadrant: 4, type: 'molar', arabicName: 'الضرس الأول' },
            29: { position: { x: 170, y: 185 }, name: 'Second Premolar', quadrant: 4, type: 'premolar', arabicName: 'الضاحك الثاني' },
            28: { position: { x: 210, y: 180 }, name: 'First Premolar', quadrant: 4, type: 'premolar', arabicName: 'الضاحك الأول' },
            27: { position: { x: 250, y: 175 }, name: 'Canine', quadrant: 4, type: 'canine', arabicName: 'الناب' },
            26: { position: { x: 290, y: 170 }, name: 'Lateral Incisor', quadrant: 4, type: 'incisor', arabicName: 'القاطع الجانبي' },
            25: { position: { x: 330, y: 165 }, name: 'Central Incisor', quadrant: 4, type: 'incisor', arabicName: 'القاطع المركزي' },
            
            24: { position: { x: 370, y: 165 }, name: 'Central Incisor', quadrant: 3, type: 'incisor', arabicName: 'القاطع المركزي' },
            23: { position: { x: 410, y: 170 }, name: 'Lateral Incisor', quadrant: 3, type: 'incisor', arabicName: 'القاطع الجانبي' },
            22: { position: { x: 450, y: 175 }, name: 'Canine', quadrant: 3, type: 'canine', arabicName: 'الناب' },
            21: { position: { x: 490, y: 180 }, name: 'First Premolar', quadrant: 3, type: 'premolar', arabicName: 'الضاحك الأول' },
            20: { position: { x: 530, y: 185 }, name: 'Second Premolar', quadrant: 3, type: 'premolar', arabicName: 'الضاحك الثاني' },
            19: { position: { x: 570, y: 190 }, name: 'First Molar', quadrant: 3, type: 'molar', arabicName: 'الضرس الأول' },
            18: { position: { x: 610, y: 195 }, name: 'Second Molar', quadrant: 3, type: 'molar', arabicName: 'الضرس الثاني' },
            17: { position: { x: 650, y: 200 }, name: 'Third Molar', quadrant: 3, type: 'molar', arabicName: 'الضرس الثالث' }
        };
    }
    
    createQuadrantBasedPositions() {
        // Four-quadrant layout positions
        const basePositions = {
            // Quadrant 1 (Upper Right) - من اليمين لليسار
            1: {
                18: { x: 120, y: 50 },   // Third Molar
                17: { x: 150, y: 50 },   // Second Molar  
                16: { x: 180, y: 50 },   // First Molar
                15: { x: 210, y: 50 },   // Second Premolar
                14: { x: 240, y: 50 },   // First Premolar
                13: { x: 270, y: 50 },   // Canine
                12: { x: 300, y: 50 },   // Lateral Incisor
                11: { x: 330, y: 50 }    // Central Incisor
            },
            // Quadrant 2 (Upper Left) - من اليسار لليمين
            2: {
                21: { x: 370, y: 50 },   // Central Incisor
                22: { x: 400, y: 50 },   // Lateral Incisor
                23: { x: 430, y: 50 },   // Canine
                24: { x: 460, y: 50 },   // First Premolar
                25: { x: 490, y: 50 },   // Second Premolar
                26: { x: 520, y: 50 },   // First Molar
                27: { x: 550, y: 50 },   // Second Molar
                28: { x: 580, y: 50 }    // Third Molar
            },
            // Quadrant 3 (Lower Left) - من اليسار لليمين
            3: {
                31: { x: 370, y: 200 },  // Central Incisor
                32: { x: 400, y: 200 },  // Lateral Incisor
                33: { x: 430, y: 200 },  // Canine
                34: { x: 460, y: 200 },  // First Premolar
                35: { x: 490, y: 200 },  // Second Premolar
                36: { x: 520, y: 200 },  // First Molar
                37: { x: 550, y: 200 },  // Second Molar
                38: { x: 580, y: 200 }   // Third Molar
            },
            // Quadrant 4 (Lower Right) - من اليمين لليسار
            4: {
                48: { x: 120, y: 200 },  // Third Molar
                47: { x: 150, y: 200 },  // Second Molar
                46: { x: 180, y: 200 },  // First Molar
                45: { x: 210, y: 200 },  // Second Premolar
                44: { x: 240, y: 200 },  // First Premolar
                43: { x: 270, y: 200 },  // Canine
                42: { x: 300, y: 200 },  // Lateral Incisor
                41: { x: 330, y: 200 }   // Central Incisor
            }
        };
        
        return basePositions;
    }
    
    createPalmerSystem() {
        const positions = this.createUniversalSystem();
        const palmerMap = {
            // Upper Right (Quadrant 1)
            1: '8', 2: '7', 3: '6', 4: '5', 5: '4', 6: '3', 7: '2', 8: '1',
            // Upper Left (Quadrant 2)
            9: '1', 10: '2', 11: '3', 12: '4', 13: '5', 14: '6', 15: '7', 16: '8',
            // Lower Left (Quadrant 3)
            17: '8', 18: '7', 19: '6', 20: '5', 21: '4', 22: '3', 23: '2', 24: '1',
            // Lower Right (Quadrant 4)
            25: '1', 26: '2', 27: '3', 28: '4', 29: '5', 30: '6', 31: '7', 32: '8'
        };
        
        const palmer = {};
        Object.keys(positions).forEach(universalId => {
            const palmerNotation = palmerMap[universalId] + this.getPalmerQuadrantSymbol(positions[universalId].quadrant);
            palmer[palmerNotation] = { ...positions[universalId], universalId };
        });
        
        return palmer;
    }
    
    createFDISystem() {
        const quadrantPositions = this.createQuadrantBasedPositions();
        const fdi = {};
        
        // FDI tooth names in Arabic
        const toothNames = {
            1: { name: 'Central Incisor', type: 'incisor', arabicName: 'القاطع المركزي' },
            2: { name: 'Lateral Incisor', type: 'incisor', arabicName: 'القاطع الجانبي' },
            3: { name: 'Canine', type: 'canine', arabicName: 'الناب' },
            4: { name: 'First Premolar', type: 'premolar', arabicName: 'الضاحك الأول' },
            5: { name: 'Second Premolar', type: 'premolar', arabicName: 'الضاحك الثاني' },
            6: { name: 'First Molar', type: 'molar', arabicName: 'الضرس الأول' },
            7: { name: 'Second Molar', type: 'molar', arabicName: 'الضرس الثاني' },
            8: { name: 'Third Molar', type: 'molar', arabicName: 'ضرس العقل' }
        };
        
        // Generate FDI teeth for each quadrant
        Object.keys(quadrantPositions).forEach(quadrant => {
            Object.keys(quadrantPositions[quadrant]).forEach(fdiNotation => {
                const position = quadrantPositions[quadrant][fdiNotation];
                const toothNumber = parseInt(fdiNotation.toString().slice(-1));
                const toothInfo = toothNames[toothNumber];
                
                fdi[fdiNotation] = {
                    position: position,
                    name: toothInfo.name,
                    type: toothInfo.type,
                    arabicName: toothInfo.arabicName,
                    quadrant: parseInt(quadrant)
                };
            });
        });
        
        return fdi;
    }
    
    createISOSystem() {
        const quadrantPositions = this.createQuadrantBasedPositions();
        const iso = {};
        
        // ISO 3950 uses letters A-H for different tooth types across quadrants
        // Quadrant notation: Upper Right, Upper Left, Lower Left, Lower Right
        const isoMapping = {
            // Upper Right Quadrant (Quadrant 1)
            1: {
                18: 'A8', 17: 'A7', 16: 'A6', 15: 'A5', 14: 'A4', 13: 'A3', 12: 'A2', 11: 'A1'
            },
            // Upper Left Quadrant (Quadrant 2) 
            2: {
                21: 'B1', 22: 'B2', 23: 'B3', 24: 'B4', 25: 'B5', 26: 'B6', 27: 'B7', 28: 'B8'
            },
            // Lower Left Quadrant (Quadrant 3)
            3: {
                31: 'C1', 32: 'C2', 33: 'C3', 34: 'C4', 35: 'C5', 36: 'C6', 37: 'C7', 38: 'C8'
            },
            // Lower Right Quadrant (Quadrant 4)
            4: {
                41: 'D1', 42: 'D2', 43: 'D3', 44: 'D4', 45: 'D5', 46: 'D6', 47: 'D7', 48: 'D8'
            }
        };
        
        // Tooth names in Arabic
        const toothNames = {
            1: { name: 'Central Incisor', type: 'incisor', arabicName: 'القاطع المركزي' },
            2: { name: 'Lateral Incisor', type: 'incisor', arabicName: 'القاطع الجانبي' },
            3: { name: 'Canine', type: 'canine', arabicName: 'الناب' },
            4: { name: 'First Premolar', type: 'premolar', arabicName: 'الضاحك الأول' },
            5: { name: 'Second Premolar', type: 'premolar', arabicName: 'الضاحك الثاني' },
            6: { name: 'First Molar', type: 'molar', arabicName: 'الضرس الأول' },
            7: { name: 'Second Molar', type: 'molar', arabicName: 'الضرس الثاني' },
            8: { name: 'Third Molar', type: 'molar', arabicName: 'ضرس العقل' }
        };
        
        // Generate ISO teeth for each quadrant
        Object.keys(quadrantPositions).forEach(quadrant => {
            Object.keys(quadrantPositions[quadrant]).forEach(fdiNotation => {
                const position = quadrantPositions[quadrant][fdiNotation];
                const isoNotation = isoMapping[quadrant][fdiNotation];
                const toothNumber = parseInt(fdiNotation.toString().slice(-1));
                const toothInfo = toothNames[toothNumber];
                
                if (isoNotation) {
                    iso[isoNotation] = {
                        position: position,
                        name: toothInfo.name,
                        type: toothInfo.type,
                        arabicName: toothInfo.arabicName,
                        quadrant: parseInt(quadrant),
                        fdiEquivalent: fdiNotation
                    };
                }
            });
        });
        
        return iso;
    }
    
    createCustomSystem() {
        const quadrantPositions = this.createQuadrantBasedPositions();
        const custom = {};
        
        // Custom tooth numbering: Upper: 18-11, 21-28, Lower: 8-1, 1-8
        const customMapping = {
            // Upper Right Quadrant (18-11)
            1: {
                18: '18', 17: '17', 16: '16', 15: '15', 14: '14', 13: '13', 12: '12', 11: '11'
            },
            // Upper Left Quadrant (21-28)
            2: {
                21: '21', 22: '22', 23: '23', 24: '24', 25: '25', 26: '26', 27: '27', 28: '28'
            },
            // Lower Left Quadrant (1-8)
            3: {
                31: '1', 32: '2', 33: '3', 34: '4', 35: '5', 36: '6', 37: '7', 38: '8'
            },
            // Lower Right Quadrant (8-1)
            4: {
                48: '8', 47: '7', 46: '6', 45: '5', 44: '4', 43: '3', 42: '2', 41: '1'
            }
        };
        
        // Tooth names in Arabic
        const toothNames = {
            1: { name: 'Central Incisor', type: 'incisor', arabicName: 'القاطع المركزي' },
            2: { name: 'Lateral Incisor', type: 'incisor', arabicName: 'القاطع الجانبي' },
            3: { name: 'Canine', type: 'canine', arabicName: 'الناب' },
            4: { name: 'First Premolar', type: 'premolar', arabicName: 'الضاحك الأول' },
            5: { name: 'Second Premolar', type: 'premolar', arabicName: 'الضاحك الثاني' },
            6: { name: 'First Molar', type: 'molar', arabicName: 'الضرس الأول' },
            7: { name: 'Second Molar', type: 'molar', arabicName: 'الضرس الثاني' },
            8: { name: 'Third Molar', type: 'molar', arabicName: 'ضرس العقل' }
        };
        
        // Generate custom teeth for each quadrant
        Object.keys(quadrantPositions).forEach(quadrant => {
            Object.keys(quadrantPositions[quadrant]).forEach(fdiNotation => {
                const position = quadrantPositions[quadrant][fdiNotation];
                const customNotation = customMapping[quadrant][fdiNotation];
                const toothNumber = parseInt(fdiNotation.toString().slice(-1));
                const toothInfo = toothNames[toothNumber];
                
                if (customNotation && toothInfo) {
                    custom[customNotation] = {
                        position: position,
                        name: toothInfo.name,
                        type: toothInfo.type,
                        arabicName: toothInfo.arabicName,
                        quadrant: parseInt(quadrant),
                        fdiEquivalent: fdiNotation
                    };
                }
            });
        });
        
        return custom;
    }
    
    getPalmerQuadrantSymbol(quadrant) {
        const symbols = {
            1: '⏌', // Upper Right
            2: '⏋', // Upper Left
            3: '⎿', // Lower Left
            4: '⏌' // Lower Right (rotated)
        };
        return symbols[quadrant] || '';
    }
    
    createNotationSelector() {
        const selectorHtml = `
            <div class="notation-selector mb-4">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">نظام ترقيم الأسنان:</label>
                        <div class="flex space-x-4 space-x-reverse flex-wrap">
                            <label class="inline-flex items-center mb-2">
                                <input type="radio" name="notation" value="custom" ${ this.notationSystem === 'custom' ? 'checked' : '' } class="form-radio text-blue-600">
                                <span class="mr-2">مخصص (18-11|21-28|1-8|8-1)</span>
                            </label>
                            <label class="inline-flex items-center mb-2">
                                <input type="radio" name="notation" value="universal" ${ this.notationSystem === 'universal' ? 'checked' : '' } class="form-radio text-blue-600">
                                <span class="mr-2">الأمريكي (1-32)</span>
                            </label>
                            <label class="inline-flex items-center mb-2">
                                <input type="radio" name="notation" value="fdi" ${ this.notationSystem === 'fdi' ? 'checked' : '' } class="form-radio text-blue-600">
                                <span class="mr-2">العالمي FDI (11-48)</span>
                            </label>
                            <label class="inline-flex items-center mb-2">
                                <input type="radio" name="notation" value="iso" ${ this.notationSystem === 'iso' ? 'checked' : '' } class="form-radio text-blue-600">
                                <span class="mr-2">ISO 3950 (A1-H8)</span>
                            </label>
                            <label class="inline-flex items-center mb-2">
                                <input type="radio" name="notation" value="palmer" ${ this.notationSystem === 'palmer' ? 'checked' : '' } class="form-radio text-blue-600">
                                <span class="mr-2">بالمر Palmer</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">نمط التحديد:</label>
                        <div class="flex space-x-2 space-x-reverse">
                            <label class="inline-flex items-center">
                                <input type="radio" name="selectionMode" value="whole" ${ !this.segmentMode ? 'checked' : '' } class="form-radio text-green-600">
                                <span class="mr-2">السن كاملاً</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="selectionMode" value="segment" ${ this.segmentMode ? 'checked' : '' } class="form-radio text-purple-600">
                                <span class="mr-2">أجزاء السن</span>
                            </label>
                        </div>
                    </div>
                </div>
                ${ this.segmentMode ? `
                    <div class="segment-legend p-3 bg-gray-50 border border-gray-200 rounded-lg">
                        <h5 class="text-sm font-semibold text-gray-700 mb-2">أجزاء السن - أشكال وأكواد مختلفة:</h5>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                            <div class="flex items-center p-2 bg-white rounded border">
                                <div class="w-6 h-6 bg-green-100 border border-green-300 mr-2 flex items-center justify-center font-bold text-green-700">B</div>
                                <div>
                                    <div class="font-semibold">دهليزي (Buccal)</div>
                                    <div class="text-gray-500">منحني علوي</div>
                                </div>
                            </div>
                            <div class="flex items-center p-2 bg-white rounded border">
                                <div class="w-6 h-6 bg-blue-100 border border-blue-300 mr-2 flex items-center justify-center font-bold text-blue-700">M</div>
                                <div>
                                    <div class="font-semibold">أنسي (Mesial)</div>
                                    <div class="text-gray-500">منحني أيسر</div>
                                </div>
                            </div>
                            <div class="flex items-center p-2 bg-white rounded border">
                                <div class="w-6 h-6 bg-orange-100 border border-orange-300 mr-2 flex items-center justify-center font-bold text-orange-700">L</div>
                                <div>
                                    <div class="font-semibold">لساني (Lingual)</div>
                                    <div class="text-gray-500">مدور سفلي</div>
                                </div>
                            </div>
                            <div class="flex items-center p-2 bg-white rounded border">
                                <div class="w-6 h-6 bg-purple-100 border border-purple-300 mr-2 flex items-center justify-center font-bold text-purple-700">D</div>
                                <div>
                                    <div class="font-semibold">وحشي (Distal)</div>
                                    <div class="text-gray-500">زاوي أيمن</div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-gray-600">
                            <i class="fas fa-info-circle ml-1"></i>
                            كل جزء له شكل فريد وكود منفصل للتتبع الدقيق
                        </div>
                    </div>
                ` : '' }
            </div>
        `;
        
        // Insert before the chart container
        this.container.insertAdjacentHTML('beforebegin', selectorHtml);
        
        // Add event listeners for notation change
        document.querySelectorAll('input[name="notation"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                this.changeNotationSystem(e.target.value);
            });
        });
        
        // Add event listeners for selection mode change
        document.querySelectorAll('input[name="selectionMode"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                this.toggleSelectionMode(e.target.value === 'segment');
            });
        });
    }
    
    createChart() {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', '700');
        svg.setAttribute('height', '280');
        svg.setAttribute('viewBox', '0 0 700 280');
        svg.classList.add('dental-chart');
        
        // Create gradient definitions
        this.createGradients(svg);
        
        // Create background
        const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        bg.setAttribute('width', '100%');
        bg.setAttribute('height', '100%');
        bg.setAttribute('fill', 'url(#backgroundGradient)');
        bg.setAttribute('stroke', '#e2e8f0');
        bg.setAttribute('stroke-width', '1');
        svg.appendChild(bg);
        
        // Draw quadrant separators (for FDI, ISO and custom systems)
        if (this.notationSystem === 'fdi' || this.notationSystem === 'iso' || this.notationSystem === 'custom') {
            this.drawQuadrantSeparators(svg);
        } else {
            // Draw jaw outlines for other systems
            this.drawJawOutlines(svg);
        }
        
        // Create teeth
        Object.keys(this.adultTeeth).forEach(toothId => {
            this.createTooth(svg, toothId, this.adultTeeth[toothId]);
        });
        
        // Add quadrant labels
        this.addQuadrantLabels(svg);
        
        this.container.innerHTML = '';
        this.container.appendChild(svg);
    }
    
    drawQuadrantSeparators(svg) {
        // Vertical center line
        const verticalLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        verticalLine.setAttribute('x1', '350');
        verticalLine.setAttribute('y1', '30');
        verticalLine.setAttribute('x2', '350');
        verticalLine.setAttribute('y2', '250');
        verticalLine.setAttribute('stroke', '#94a3b8');
        verticalLine.setAttribute('stroke-width', '2');
        verticalLine.setAttribute('stroke-dasharray', '5,5');
        svg.appendChild(verticalLine);
        
        // Horizontal center line
        const horizontalLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        horizontalLine.setAttribute('x1', '100');
        horizontalLine.setAttribute('y1', '125');
        horizontalLine.setAttribute('x2', '600');
        horizontalLine.setAttribute('y2', '125');
        horizontalLine.setAttribute('stroke', '#94a3b8');
        horizontalLine.setAttribute('stroke-width', '2');
        horizontalLine.setAttribute('stroke-dasharray', '5,5');
        svg.appendChild(horizontalLine);
        
        // Quadrant background rectangles
        const quadrantBgs = [
            { x: 100, y: 30, quadrant: 1, color: '#fef2f2' },  // Upper Right - Light Red
            { x: 350, y: 30, quadrant: 2, color: '#f0fdf4' },  // Upper Left - Light Green
            { x: 350, y: 125, quadrant: 3, color: '#fffbeb' }, // Lower Left - Light Yellow
            { x: 100, y: 125, quadrant: 4, color: '#f0f9ff' }  // Lower Right - Light Blue
        ];
        
        quadrantBgs.forEach(bg => {
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', bg.x);
            rect.setAttribute('y', bg.y);
            rect.setAttribute('width', '250');
            rect.setAttribute('height', '95');
            rect.setAttribute('fill', bg.color);
            rect.setAttribute('opacity', '0.3');
            rect.setAttribute('stroke', '#e5e7eb');
            rect.setAttribute('stroke-width', '1');
            svg.appendChild(rect);
        });
    }
    
    createGradients(svg) {
        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        
        // Background gradient
        const bgGradient = document.createElementNS('http://www.w3.org/2000/svg', 'linearGradient');
        bgGradient.setAttribute('id', 'backgroundGradient');
        bgGradient.setAttribute('x1', '0%');
        bgGradient.setAttribute('y1', '0%');
        bgGradient.setAttribute('x2', '100%');
        bgGradient.setAttribute('y2', '100%');
        
        const bgStop1 = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
        bgStop1.setAttribute('offset', '0%');
        bgStop1.setAttribute('stop-color', '#f8fafc');
        
        const bgStop2 = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
        bgStop2.setAttribute('offset', '100%');
        bgStop2.setAttribute('stop-color', '#e2e8f0');
        
        bgGradient.appendChild(bgStop1);
        bgGradient.appendChild(bgStop2);
        defs.appendChild(bgGradient);
        
        // Treatment status gradients
        const gradients = {
            'healthyGradient': ['#4ade80', '#22c55e'],
            'cariesGradient': ['#ef4444', '#dc2626'],
            'fillingGradient': ['#3b82f6', '#1d4ed8'],
            'crownGradient': ['#f59e0b', '#d97706'],
            'rootCanalGradient': ['#8b5cf6', '#7c3aed']
        };
        
        Object.keys(gradients).forEach(gradientId => {
            const gradient = document.createElementNS('http://www.w3.org/2000/svg', 'linearGradient');
            gradient.setAttribute('id', gradientId);
            gradient.setAttribute('x1', '0%');
            gradient.setAttribute('y1', '0%');
            gradient.setAttribute('x2', '100%');
            gradient.setAttribute('y2', '100%');
            
            const stop1 = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
            stop1.setAttribute('offset', '0%');
            stop1.setAttribute('stop-color', gradients[gradientId][0]);
            
            const stop2 = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
            stop2.setAttribute('offset', '100%');
            stop2.setAttribute('stop-color', gradients[gradientId][1]);
            
            gradient.appendChild(stop1);
            gradient.appendChild(stop2);
            defs.appendChild(gradient);
        });
        
        svg.appendChild(defs);
    }
    
    drawJawOutlines(svg) {
        // Upper jaw outline
        const upperJaw = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        upperJaw.setAttribute('d', 'M 30 70 Q 350 5 670 70');
        upperJaw.setAttribute('stroke', '#94a3b8');
        upperJaw.setAttribute('stroke-width', '2');
        upperJaw.setAttribute('fill', 'none');
        svg.appendChild(upperJaw);
        
        // Lower jaw outline
        const lowerJaw = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        lowerJaw.setAttribute('d', 'M 30 180 Q 350 245 670 180');
        lowerJaw.setAttribute('stroke', '#94a3b8');
        lowerJaw.setAttribute('stroke-width', '2');
        lowerJaw.setAttribute('fill', 'none');
        svg.appendChild(lowerJaw);
    }
    
    createTooth(svg, toothId, toothData) {
        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        group.classList.add('tooth-group');
        group.setAttribute('data-tooth-id', toothId);
        group.setAttribute('data-tooth-type', toothData.type);
        
        // Get tooth dimensions
        const toothSize = this.toothTypes[toothData.type] || this.toothTypes['molar'];
        const centerX = toothData.position.x;
        const centerY = toothData.position.y;
        const halfWidth = toothSize.width / 2;
        const halfHeight = toothSize.height / 2;
        
        // Create four distinct segments with different shapes and coding for each quarter
        const segments = [
            {
                id: 'buccal',
                code: 'B',
                path: `M ${centerX - halfWidth * 0.7} ${centerY - halfHeight} 
                       Q ${centerX} ${centerY - halfHeight * 1.2} ${centerX + halfWidth * 0.7} ${centerY - halfHeight}
                       L ${centerX + halfWidth * 0.3} ${centerY - halfHeight * 0.2}
                       Q ${centerX} ${centerY - halfHeight * 0.3} ${centerX - halfWidth * 0.3} ${centerY - halfHeight * 0.2} Z`,
                name: this.toothSegments.buccal.arabicName,
                shape: 'curved-top'
            },
            {
                id: 'mesial',
                code: 'M',
                path: `M ${centerX - halfWidth} ${centerY - halfHeight * 0.8}
                       L ${centerX - halfWidth * 0.3} ${centerY - halfHeight * 0.2}
                       Q ${centerX - halfWidth * 0.2} ${centerY} ${centerX - halfWidth * 0.3} ${centerY + halfHeight * 0.2}
                       L ${centerX - halfWidth} ${centerY + halfHeight * 0.8}
                       Q ${centerX - halfWidth * 1.1} ${centerY} ${centerX - halfWidth} ${centerY - halfHeight * 0.8} Z`,
                name: this.toothSegments.mesial.arabicName,
                shape: 'curved-left'
            },
            {
                id: 'lingual',
                code: 'L',
                path: `M ${centerX - halfWidth * 0.3} ${centerY + halfHeight * 0.2}
                       Q ${centerX} ${centerY + halfHeight * 0.3} ${centerX + halfWidth * 0.3} ${centerY + halfHeight * 0.2}
                       L ${centerX + halfWidth * 0.8} ${centerY + halfHeight}
                       Q ${centerX} ${centerY + halfHeight * 1.3} ${centerX - halfWidth * 0.8} ${centerY + halfHeight}
                       L ${centerX - halfWidth * 0.3} ${centerY + halfHeight * 0.2} Z`,
                name: this.toothSegments.lingual.arabicName,
                shape: 'rounded-bottom'
            },
            {
                id: 'distal',
                code: 'D',
                path: `M ${centerX + halfWidth * 0.3} ${centerY - halfHeight * 0.2}
                       L ${centerX + halfWidth} ${centerY - halfHeight * 0.8}
                       Q ${centerX + halfWidth * 1.1} ${centerY} ${centerX + halfWidth} ${centerY + halfHeight * 0.8}
                       L ${centerX + halfWidth * 0.3} ${centerY + halfHeight * 0.2}
                       Q ${centerX + halfWidth * 0.5} ${centerY} ${centerX + halfWidth * 0.3} ${centerY - halfHeight * 0.2} Z`,
                name: this.toothSegments.distal.arabicName,
                shape: 'angular-right'
            }
        ];
        
        // Create shadow first
        const shadow = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        shadow.setAttribute('x', centerX - halfWidth + 1);
        shadow.setAttribute('y', centerY - halfHeight + 1);
        shadow.setAttribute('width', toothSize.width);
        shadow.setAttribute('height', toothSize.height);
        shadow.setAttribute('rx', '2');
        shadow.setAttribute('fill', 'rgba(0, 0, 0, 0.1)');
        shadow.setAttribute('opacity', '0.5');
        group.appendChild(shadow);
        
        // Create each segment with unique shapes
        segments.forEach((segment, index) => {
            const segmentPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            segmentPath.setAttribute('d', segment.path);
            segmentPath.setAttribute('fill', this.getSegmentColor(toothId, segment.id));
            segmentPath.setAttribute('stroke', '#64748b');
            segmentPath.setAttribute('stroke-width', '0.5');
            segmentPath.classList.add('tooth-segment');
            segmentPath.classList.add(`segment-${segment.shape}`);
            segmentPath.setAttribute('data-segment', segment.id);
            segmentPath.setAttribute('data-segment-code', segment.code);
            segmentPath.setAttribute('data-segment-name', segment.name);
            segmentPath.setAttribute('data-segment-shape', segment.shape);
            
            // Add segment interaction
            segmentPath.addEventListener('mouseenter', (e) => {
                e.stopPropagation();
                this.showSegmentInfo(toothId, segment.id, toothData, e, segment);
                segmentPath.style.filter = 'brightness(1.2)';
            });
            
            segmentPath.addEventListener('mouseleave', (e) => {
                e.stopPropagation();
                this.hideToothInfo();
                segmentPath.style.filter = 'none';
            });
            
            segmentPath.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.segmentMode) {
                    this.selectSegment(toothId, segment.id);
                } else {
                    this.selectTooth(toothId);
                }
            });
            
            group.appendChild(segmentPath);
            
            // Add segment code label
            const segmentLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            segmentLabel.setAttribute('x', this.getSegmentLabelPosition(segment.id, centerX, centerY, halfWidth, halfHeight).x);
            segmentLabel.setAttribute('y', this.getSegmentLabelPosition(segment.id, centerX, centerY, halfWidth, halfHeight).y);
            segmentLabel.setAttribute('text-anchor', 'middle');
            segmentLabel.setAttribute('font-size', '8');
            segmentLabel.setAttribute('font-weight', 'bold');
            segmentLabel.setAttribute('fill', '#374151');
            segmentLabel.setAttribute('opacity', '0.8');
            segmentLabel.classList.add('segment-label');
            segmentLabel.textContent = segment.code;
            group.appendChild(segmentLabel);
        });
        
        // Add tooth border
        const border = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        border.setAttribute('x', centerX - halfWidth);
        border.setAttribute('y', centerY - halfHeight);
        border.setAttribute('width', toothSize.width);
        border.setAttribute('height', toothSize.height);
        border.setAttribute('rx', '2');
        border.setAttribute('fill', 'none');
        border.setAttribute('stroke', '#64748b');
        border.setAttribute('stroke-width', '2');
        border.classList.add('tooth-border');
        group.appendChild(border);
        
        // Add tooth icon overlay
        const toothIcon = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        toothIcon.setAttribute('x', centerX);
        toothIcon.setAttribute('y', centerY - halfHeight - 8);
        toothIcon.setAttribute('text-anchor', 'middle');
        toothIcon.setAttribute('font-family', 'Font Awesome 6 Free');
        toothIcon.setAttribute('font-weight', '900');
        toothIcon.setAttribute('font-size', '12');
        toothIcon.setAttribute('fill', '#374151');
        toothIcon.setAttribute('opacity', '0.7');
        toothIcon.textContent = '\uf5c9'; // Font Awesome tooth icon
        group.appendChild(toothIcon);
        
        // Tooth number/notation
        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', centerX);
        text.setAttribute('y', centerY + halfHeight + 15);
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('font-size', '10');
        text.setAttribute('font-weight', 'bold');
        text.setAttribute('fill', '#1e293b');
        text.textContent = toothId;
        group.appendChild(text);
        
        svg.appendChild(group);
        
        // Add whole tooth interaction for non-segment mode
        group.addEventListener('mouseenter', () => {
            if (!this.segmentMode) {
                this.showToothInfo(toothId, toothData);
                group.classList.add('tooth-hover');
            }
        });
        
        group.addEventListener('mouseleave', () => {
            if (!this.segmentMode) {
                this.hideToothInfo();
                group.classList.remove('tooth-hover');
            }
        });
        
        group.addEventListener('click', () => {
            if (!this.segmentMode) {
                this.selectTooth(toothId);
                group.classList.add('selecting');
                setTimeout(() => group.classList.remove('selecting'), 300);
            }
        });
    }
    
    addQuadrantLabels(svg) {
        if (this.notationSystem === 'custom') {
            // Custom system quadrant labels
            const customLabels = [
                { text: 'الربع 1', number: '18-11', x: 225, y: 20, color: '#ef4444' },
                { text: 'الربع 2', number: '21-28', x: 475, y: 20, color: '#10b981' },
                { text: 'الربع 3', number: '1-8', x: 475, y: 270, color: '#f59e0b' },
                { text: 'الربع 4', number: '8-1', x: 225, y: 270, color: '#3b82f6' }
            ];
            
            customLabels.forEach(label => {
                // Quadrant number
                const quadText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                quadText.setAttribute('x', label.x);
                quadText.setAttribute('y', label.y);
                quadText.setAttribute('text-anchor', 'middle');
                quadText.setAttribute('font-size', '14');
                quadText.setAttribute('font-weight', 'bold');
                quadText.setAttribute('fill', label.color);
                quadText.classList.add('quadrant-label');
                quadText.textContent = label.text;
                svg.appendChild(quadText);
                
                // Tooth range
                const rangeText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                rangeText.setAttribute('x', label.x);
                rangeText.setAttribute('y', label.y + (label.y < 100 ? 15 : -15));
                rangeText.setAttribute('text-anchor', 'middle');
                rangeText.setAttribute('font-size', '10');
                rangeText.setAttribute('fill', '#6b7280');
                rangeText.textContent = label.number;
                svg.appendChild(rangeText);
            });
        } else if (this.notationSystem === 'fdi') {
            // FDI Quadrant labels with numbers
            const fdiLabels = [
                { text: 'الربع 1', number: '18-11', x: 225, y: 20, color: '#ef4444' },
                { text: 'الربع 2', number: '21-28', x: 475, y: 20, color: '#10b981' },
                { text: 'الربع 3', number: '31-38', x: 475, y: 270, color: '#f59e0b' },
                { text: 'الربع 4', number: '41-48', x: 225, y: 270, color: '#3b82f6' }
            ];
            
            fdiLabels.forEach(label => {
                // Quadrant number
                const quadText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                quadText.setAttribute('x', label.x);
                quadText.setAttribute('y', label.y);
                quadText.setAttribute('text-anchor', 'middle');
                quadText.setAttribute('font-size', '14');
                quadText.setAttribute('font-weight', 'bold');
                quadText.setAttribute('fill', label.color);
                quadText.classList.add('quadrant-label');
                quadText.textContent = label.text;
                svg.appendChild(quadText);
                
                // Tooth range
                const rangeText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                rangeText.setAttribute('x', label.x);
                rangeText.setAttribute('y', label.y + (label.y < 100 ? 15 : -15));
                rangeText.setAttribute('text-anchor', 'middle');
                rangeText.setAttribute('font-size', '10');
                rangeText.setAttribute('fill', '#6b7280');
                rangeText.textContent = label.number;
                svg.appendChild(rangeText);
            });
        } else if (this.notationSystem === 'iso') {
            // ISO 3950 Quadrant labels with letters
            const isoLabels = [
                { text: 'الربع A', number: 'A8-A1', x: 225, y: 20, color: '#ef4444' },
                { text: 'الربع B', number: 'B1-B8', x: 475, y: 20, color: '#10b981' },
                { text: 'الربع C', number: 'C1-C8', x: 475, y: 270, color: '#f59e0b' },
                { text: 'الربع D', number: 'D1-D8', x: 225, y: 270, color: '#3b82f6' }
            ];
            
            isoLabels.forEach(label => {
                // Quadrant letter
                const quadText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                quadText.setAttribute('x', label.x);
                quadText.setAttribute('y', label.y);
                quadText.setAttribute('text-anchor', 'middle');
                quadText.setAttribute('font-size', '14');
                quadText.setAttribute('font-weight', 'bold');
                quadText.setAttribute('fill', label.color);
                quadText.classList.add('quadrant-label');
                quadText.textContent = label.text;
                svg.appendChild(quadText);
                
                // Tooth range
                const rangeText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                rangeText.setAttribute('x', label.x);
                rangeText.setAttribute('y', label.y + (label.y < 100 ? 15 : -15));
                rangeText.setAttribute('text-anchor', 'middle');
                rangeText.setAttribute('font-size', '10');
                rangeText.setAttribute('fill', '#6b7280');
                rangeText.textContent = label.number;
                svg.appendChild(rangeText);
            });
        } else {
            // Original labels for Universal/Palmer systems
            const labels = [
                { text: 'الربع الأول (علوي يمين)', x: 150, y: 80, color: '#3b82f6' },
                { text: 'الربع الثاني (علوي يسار)', x: 550, y: 80, color: '#10b981' },
                { text: 'الربع الثالث (سفلي يسار)', x: 550, y: 140, color: '#f59e0b' },
                { text: 'الربع الرابع (سفلي يمين)', x: 150, y: 140, color: '#ef4444' }
            ];
            
            labels.forEach(label => {
                const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                text.setAttribute('x', label.x);
                text.setAttribute('y', label.y);
                text.setAttribute('text-anchor', 'middle');
                text.setAttribute('font-size', '12');
                text.setAttribute('font-weight', 'bold');
                text.setAttribute('fill', label.color);
                text.textContent = label.text;
                svg.appendChild(text);
            });
        }
    }
    
    getToothColor(toothId) {
        if (this.treatments[toothId]) {
            const treatmentType = this.treatments[toothId].type;
            // Use gradients for better visual appeal
            switch(treatmentType) {
                case 'healthy': return 'url(#healthyGradient)';
                case 'caries': return 'url(#cariesGradient)';
                case 'filling': return 'url(#fillingGradient)';
                case 'crown': return 'url(#crownGradient)';
                case 'root_canal': return 'url(#rootCanalGradient)';
                default: return this.treatmentColors[treatmentType] || this.treatmentColors['healthy'];
            }
        }
        return 'url(#healthyGradient)';
    }
    
    getSegmentColor(toothId, segmentId) {
        // Check if this specific segment has a treatment
        if (this.treatments[toothId] && this.treatments[toothId].segments && this.treatments[toothId].segments[segmentId]) {
            const segmentTreatment = this.treatments[toothId].segments[segmentId];
            return this.treatmentColors[segmentTreatment] || this.treatmentColors['healthy'];
        }
        
        // Check if the whole tooth has a treatment
        if (this.treatments[toothId]) {
            return this.getToothColor(toothId);
        }
        
        // Default segment colors for healthy teeth
        const defaultColors = {
            'buccal': '#e8f5e8',    // Light green
            'mesial': '#e3f2fd',    // Light blue  
            'lingual': '#fff3e0',   // Light orange
            'distal': '#f3e5f5'     // Light purple
        };
        
        return defaultColors[segmentId] || '#f8fafc';
    }
    
    selectSegment(toothId, segmentId) {
        const segmentKey = `${toothId}-${segmentId}`;
        
        if (this.selectedSegments.has(segmentKey)) {
            this.selectedSegments.delete(segmentKey);
        } else {
            if (!this.isMultiSelect) {
                this.selectedSegments.clear();
            }
            this.selectedSegments.set(segmentKey, { toothId, segmentId });
        }
        
        this.updateSegmentAppearance();
        
        // Call the callback with segment information
        if (this.segmentMode) {
            this.onToothSelect(toothId, this.selectedSegments, this.adultTeeth[toothId], { 
                mode: 'segment', 
                segmentId: segmentId,
                segmentName: this.toothSegments[segmentId].arabicName,
                selectedSegments: this.getSelectedSegments()
            });
        } else {
            this.onToothSelect(toothId, this.selectedTeeth, this.adultTeeth[toothId]);
        }
    }
    
    updateSegmentAppearance() {
        // Reset all segments
        document.querySelectorAll('.tooth-segment').forEach(segment => {
            const group = segment.closest('.tooth-group');
            const toothId = group.getAttribute('data-tooth-id');
            const segmentId = segment.getAttribute('data-segment');
            
            segment.setAttribute('stroke', '#64748b');
            segment.setAttribute('stroke-width', '0.5');
            segment.setAttribute('fill', this.getSegmentColor(toothId, segmentId));
        });
        
        // Highlight selected segments
        this.selectedSegments.forEach((segmentData, segmentKey) => {
            const segmentElement = document.querySelector(
                `[data-tooth-id="${segmentData.toothId}"] [data-segment="${segmentData.segmentId}"]`
            );
            if (segmentElement) {
                segmentElement.setAttribute('stroke', '#1d4ed8');
                segmentElement.setAttribute('stroke-width', '3');
                segmentElement.style.filter = 'brightness(1.3) saturate(1.3)';
            }
        });
    }
    
    getSegmentLabelPosition(segmentId, centerX, centerY, halfWidth, halfHeight) {
        const positions = {
            'buccal': { x: centerX, y: centerY - halfHeight * 0.6 },
            'mesial': { x: centerX - halfWidth * 0.6, y: centerY },
            'lingual': { x: centerX, y: centerY + halfHeight * 0.6 },
            'distal': { x: centerX + halfWidth * 0.6, y: centerY }
        };
        return positions[segmentId] || { x: centerX, y: centerY };
    }
    
    showSegmentInfo(toothId, segmentId, toothData, event, segment) {
        const info = document.getElementById('tooth-info');
        if (info) {
            const segmentInfo = this.toothSegments[segmentId];
            const treatment = this.treatments[toothId]?.segments?.[segmentId];
            const status = treatment ? this.getTreatmentStatusText(treatment) : 'سليم';
            
            info.innerHTML = `
                <div class="p-3 bg-white border border-gray-200 rounded-lg shadow-lg">
                    <h4 class="font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-tooth text-blue-600 ml-2"></i>
                        السن ${toothId} - ${segmentInfo.arabicName}
                    </h4>
                    <p class="text-sm text-gray-600">${toothData.arabicName || toothData.name}</p>
                    <p class="text-sm"><span class="font-medium">الكود:</span> ${segment.code}</p>
                    <p class="text-sm"><span class="font-medium">السطح:</span> ${segmentInfo.name} (${segmentInfo.arabicName})</p>
                    <p class="text-sm"><span class="font-medium">الشكل:</span> ${segment.shape}</p>
                    <p class="text-sm"><span class="font-medium">الحالة:</span> ${status}</p>
                    <p class="text-sm"><span class="font-medium">الربع:</span> ${toothData.quadrant}</p>
                </div>
            `;
            
            // Position tooltip near the mouse
            info.style.position = 'fixed';
            info.style.left = (event.pageX + 10) + 'px';
            info.style.top = (event.pageY - 10) + 'px';
            info.style.display = 'block';
        }
    }
    
    selectTooth(toothId) {
        if (this.isMultiSelect) {
            if (this.selectedTeeth.has(toothId)) {
                this.selectedTeeth.delete(toothId);
            } else {
                this.selectedTeeth.add(toothId);
            }
        } else {
            this.selectedTooth = toothId;
            this.selectedTeeth.clear();
            this.selectedTeeth.add(toothId);
        }
        
        this.updateToothAppearance();
        this.onToothSelect(toothId, this.selectedTeeth, this.adultTeeth[toothId]);
    }
    
    updateToothAppearance() {
        // Reset all teeth
        document.querySelectorAll('.tooth').forEach(tooth => {
            const group = tooth.parentNode;
            const toothId = group.getAttribute('data-tooth-id');
            tooth.setAttribute('stroke', '#64748b');
            tooth.setAttribute('stroke-width', '1');
            
            // Apply treatment color
            tooth.setAttribute('fill', this.getToothColor(toothId));
        });
        
        // Highlight selected teeth
        this.selectedTeeth.forEach(toothId => {
            const group = document.querySelector(`[data-tooth-id="${toothId}"]`);
            if (group) {
                const tooth = group.querySelector('.tooth');
                tooth.setAttribute('stroke', '#1d4ed8');
                tooth.setAttribute('stroke-width', '3');
            }
        });
    }
    
    showToothInfo(toothId, toothData) {
        const info = document.getElementById('tooth-info');
        if (info) {
            const treatment = this.treatments[toothId];
            const status = treatment ? this.getTreatmentStatusText(treatment.type) : 'سليم';
            const universalId = toothData.universalId || toothId;
            
            info.innerHTML = `
                <div class="p-3 bg-white border border-gray-200 rounded-lg shadow-lg">
                    <h4 class="font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-tooth text-blue-600 ml-2"></i>
                        السن رقم ${toothId}
                    </h4>
                    <p class="text-sm text-gray-600">${toothData.arabicName || toothData.name}</p>
                    <p class="text-sm"><span class="font-medium">النوع:</span> ${this.getToothTypeText(toothData.type)}</p>
                    <p class="text-sm"><span class="font-medium">الحالة:</span> ${status}</p>
                    <p class="text-sm"><span class="font-medium">الربع:</span> ${toothData.quadrant}</p>
                    ${treatment ? `<p class="text-sm"><span class="font-medium">تاريخ العلاج:</span> ${treatment.date || 'غير محدد'}</p>` : ''}
                </div>
            `;
            info.style.display = 'block';
        }
    }
    
    getToothTypeText(type) {
        const typeMap = {
            'incisor': 'قاطع',
            'canine': 'ناب',
            'premolar': 'ضاحك',
            'molar': 'ضرس'
        };
        return typeMap[type] || 'غير محدد';
    }
    
    toggleSelectionMode(segmentMode) {
        this.segmentMode = segmentMode;
        this.selectedTeeth.clear();
        this.selectedSegments.clear();
        this.selectedTooth = null;
        
        // Add/remove segment mode CSS class
        const chart = this.container.querySelector('.dental-chart');
        if (chart) {
            if (segmentMode) {
                chart.classList.add('segment-mode');
            } else {
                chart.classList.remove('segment-mode');
            }
        }
        
        // Re-create the notation selector to show/hide segment legend
        const existingSelector = document.querySelector('.notation-selector');
        if (existingSelector) {
            existingSelector.remove();
        }
        this.createNotationSelector();
        
        // Update chart appearance
        this.updateToothAppearance();
        this.updateSegmentAppearance();
    }
    
    changeNotationSystem(newSystem) {
        this.notationSystem = newSystem;
        this.adultTeeth = this.notationSystems[newSystem];
        this.selectedTeeth.clear();
        this.selectedSegments.clear();
        this.selectedTooth = null;
        this.createChart();
    }
    
    hideToothInfo() {
        const info = document.getElementById('tooth-info');
        if (info) {
            info.style.display = 'none';
        }
    }
    
    getTreatmentStatusText(type) {
        const statusMap = {
            'healthy': 'سليم',
            'caries': 'تسوس',
            'filling': 'حشوة',
            'crown': 'تاج',
            'root_canal': 'علاج عصب',
            'extraction': 'مقلوع',
            'implant': 'زراعة',
            'bridge': 'جسر',
            'missing': 'مفقود'
        };
        return statusMap[type] || 'غير محدد';
    }
    
    addTreatment(toothId, treatment) {
        this.treatments[toothId] = treatment;
        this.updateToothAppearance();
        this.updateSegmentAppearance();
    }
    
    addSegmentTreatment(toothId, segmentId, treatmentType) {
        if (!this.treatments[toothId]) {
            this.treatments[toothId] = { segments: {} };
        }
        if (!this.treatments[toothId].segments) {
            this.treatments[toothId].segments = {};
        }
        
        this.treatments[toothId].segments[segmentId] = treatmentType;
        this.updateSegmentAppearance();
    }
    
    removeSegmentTreatment(toothId, segmentId) {
        if (this.treatments[toothId] && this.treatments[toothId].segments) {
            delete this.treatments[toothId].segments[segmentId];
            
            // If no segments have treatments and no whole-tooth treatment, remove the tooth entry
            if (Object.keys(this.treatments[toothId].segments).length === 0 && !this.treatments[toothId].type) {
                delete this.treatments[toothId];
            }
        }
        this.updateSegmentAppearance();
    }
    
    removeTreatment(toothId) {
        delete this.treatments[toothId];
        this.updateToothAppearance();
        this.updateSegmentAppearance();
    }
    
    getSelectedTeeth() {
        return Array.from(this.selectedTeeth);
    }
    
    getSelectedSegments() {
        return Array.from(this.selectedSegments.entries()).map(([key, value]) => ({
            key: key,
            toothId: value.toothId,
            segmentId: value.segmentId,
            segmentName: this.toothSegments[value.segmentId].arabicName
        }));
    }
    
    clearSelection() {
        this.selectedTeeth.clear();
        this.selectedSegments.clear();
        this.selectedTooth = null;
        this.updateToothAppearance();
        this.updateSegmentAppearance();
    }
    
    setTreatments(treatments) {
        this.treatments = treatments;
        this.updateToothAppearance();
        this.updateSegmentAppearance();
    }
    
    attachEventListeners() {
        // Add keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.clearSelection();
            }
        });
    }
}

// Treatment Stage Management
class TreatmentStages {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.stages = options.stages || [];
        this.currentStage = 0;
        this.onStageComplete = options.onStageComplete || (() => {});
        this.onStageUpdate = options.onStageUpdate || (() => {});
        this.config = null;
        
        this.loadConfiguration().then(() => {
            this.init();
        });
    }
    
    async loadConfiguration() {
        try {
            const response = await fetch('./treatment-stages-config.json');
            this.config = await response.json();
        } catch (error) {
            console.warn('Could not load treatment stages configuration, using defaults');
            this.config = this.getDefaultConfig();
        }
    }
    
    getDefaultConfig() {
        return {
            stageStyles: {
                completed: { backgroundColor: "#f0f9ff", borderColor: "#0ea5e9", textColor: "#0c4a6e", icon: "fas fa-check-circle", iconColor: "#0ea5e9" },
                current: { backgroundColor: "#fefce8", borderColor: "#eab308", textColor: "#713f12", icon: "fas fa-play-circle", iconColor: "#eab308" },
                upcoming: { backgroundColor: "#f9fafb", borderColor: "#d1d5db", textColor: "#6b7280", icon: "fas fa-clock", iconColor: "#9ca3af" }
            },
            compactMode: { enabled: true, showCompletionDate: false, showDuration: true, showNotes: true },
            typography: {
                stageTitle: { fontSize: "16px", fontWeight: "600" },
                stageDescription: { fontSize: "14px", fontWeight: "400" }
            }
        };
    }
    
    init() {
        this.render();
    }
    
    render() {
        const progressConfig = this.config.progressBarStyles;
        const animationConfig = this.config.animations.progressBar;
        
        this.container.innerHTML = `
            <div class="treatment-stages">
                <div class="stages-header mb-4">
                    <h4 class="text-lg font-semibold text-gray-800 mb-2">مراحل العلاج</h4>
                    <div class="progress-bar rounded-full" style="background-color: ${progressConfig.backgroundColor}; height: ${progressConfig.height}; border-radius: ${progressConfig.borderRadius};">
                        <div class="progress-fill rounded-full" 
                             style="background-color: ${progressConfig.fillColor}; height: ${progressConfig.height}; border-radius: ${progressConfig.borderRadius}; width: ${this.getProgressPercentage()}%; transition: width ${animationConfig.duration} ${animationConfig.easing};"></div>
                    </div>
                </div>
                <div class="stages-list" style="gap: ${this.config.layout.spacing.stageGap}; display: flex; flex-direction: column;">
                    ${this.stages.map((stage, index) => this.renderStage(stage, index)).join('')}
                </div>
                ${this.stages.length > 0 ? this.renderControls() : ''}
            </div>
        `;
        
        this.attachStageListeners();
    }
    
    renderStage(stage, index) {
        const isCompleted = index < this.currentStage;
        const isCurrent = index === this.currentStage;
        const isUpcoming = index > this.currentStage;
        
        let stageStyle = {};
        let statusIcon = '';
        
        if (isCompleted) {
            stageStyle = this.config.stageStyles.completed;
            statusIcon = `<i class="${stageStyle.icon}" style="color: ${stageStyle.iconColor}"></i>`;
        } else if (isCurrent) {
            stageStyle = this.config.stageStyles.current;
            statusIcon = `<i class="${stageStyle.icon}" style="color: ${stageStyle.iconColor}"></i>`;
        } else {
            stageStyle = this.config.stageStyles.upcoming;
            statusIcon = `<i class="${stageStyle.icon}" style="color: ${stageStyle.iconColor}"></i>`;
        }
        
        const stageStyles = `background-color: ${stageStyle.backgroundColor}; border-color: ${stageStyle.borderColor}; color: ${stageStyle.textColor};`;
        const titleStyles = `font-size: ${this.config.typography.stageTitle.fontSize}; font-weight: ${this.config.typography.stageTitle.fontWeight};`;
        const descriptionStyles = `font-size: ${this.config.typography.stageDescription.fontSize}; font-weight: ${this.config.typography.stageDescription.fontWeight};`;
        
        const layoutStyles = `padding: ${this.config.layout.spacing.contentPadding}; border-width: ${this.config.layout.borders.width}; border-style: ${this.config.layout.borders.style}; border-radius: ${this.config.layout.borders.radius};`;
        
        return `
            <div class="stage-item border" style="${stageStyles} ${layoutStyles}" data-stage-index="${index}">
                <div class="flex items-start justify-between">
                    <div class="flex items-start space-x-reverse" style="gap: ${this.config.layout.spacing.iconMargin};">
                        <div class="stage-icon mt-1">${statusIcon}</div>
                        <div class="stage-content">
                            <h5 style="${titleStyles}">${stage.title}</h5>
                            <p style="${descriptionStyles}" class="mt-1">${stage.description}</p>
                            ${this.config.compactMode.showDuration && stage.duration ? `<span style="font-size: ${this.config.typography.stageDuration.fontSize}; font-weight: ${this.config.typography.stageDuration.fontWeight}; opacity: ${this.config.typography.stageDuration.opacity};">المدة المتوقعة: ${stage.duration}</span>` : ''}
                        </div>
                    </div>
                    <div class="stage-actions">
                        ${isCurrent ? `<button class="complete-stage-btn btn btn-sm bg-green-500 text-white px-3 py-1 rounded text-xs">إكمال</button>` : ''}
                        ${isCompleted ? `<button class="edit-stage-btn btn btn-sm bg-gray-500 text-white px-3 py-1 rounded text-xs">تعديل</button>` : ''}
                    </div>
                </div>
                ${isCurrent || isCompleted ? this.renderStageDetails(stage, index) : ''}
            </div>
        `;
    }
    
    renderStageDetails(stage, index) {
        const compactConfig = this.config.compactMode;
        let notesSection = '';
        
        if (compactConfig.showNotes) {
            const maxLength = compactConfig.maxNotesLength || 200;
            notesSection = `
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات المرحلة</label>
                    <textarea class="stage-notes w-full p-2 border border-gray-300 rounded text-sm" 
                              rows="2" maxlength="${maxLength}" 
                              placeholder="أضف ملاحظات لهذه المرحلة...">${stage.notes || ''}</textarea>
                </div>
            `;
        }
        
        return `
            <div class="stage-details mt-4 p-3 bg-white border border-gray-200 rounded">
                <div class="grid grid-cols-1 gap-4">
                    ${notesSection}
                </div>
            </div>
        `;
    }
    
    renderControls() {
        return `
            <div class="stages-controls mt-6 flex justify-between items-center">
                <div class="stage-progress">
                    <span class="text-sm text-gray-600">المرحلة ${this.currentStage + 1} من ${this.stages.length}</span>
                </div>
                <div class="stage-buttons space-x-2 space-x-reverse">
                    <button class="prev-stage-btn btn bg-gray-500 text-white px-4 py-2 rounded text-sm" 
                            ${this.currentStage === 0 ? 'disabled' : ''}>السابق</button>
                    <button class="next-stage-btn btn bg-blue-500 text-white px-4 py-2 rounded text-sm"
                            ${this.currentStage >= this.stages.length - 1 ? 'disabled' : ''}>التالي</button>
                </div>
            </div>
        `;
    }
    
    attachStageListeners() {
        // Complete stage buttons
        this.container.querySelectorAll('.complete-stage-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const stageIndex = parseInt(e.target.closest('.stage-item').dataset.stageIndex);
                this.completeStage(stageIndex);
            });
        });
        
        // Navigation buttons
        const prevBtn = this.container.querySelector('.prev-stage-btn');
        const nextBtn = this.container.querySelector('.next-stage-btn');
        
        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.goToPreviousStage());
        }
        
        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.goToNextStage());
        }
        
        // Stage notes changes
        this.container.querySelectorAll('.stage-notes').forEach(input => {
            input.addEventListener('change', (e) => {
                const stageItem = e.target.closest('.stage-item');
                const stageIndex = parseInt(stageItem.dataset.stageIndex);
                this.updateStageData(stageIndex, e.target);
            });
        });
    }
    
    completeStage(stageIndex) {
        if (stageIndex === this.currentStage) {
            this.stages[stageIndex].completed = true;
            this.stages[stageIndex].completedDate = new Date().toISOString().split('T')[0];
            
            if (this.currentStage < this.stages.length - 1) {
                this.currentStage++;
            }
            
            this.render();
            this.onStageComplete(stageIndex, this.stages[stageIndex]);
        }
    }
    
    updateStageData(stageIndex, input) {
        const stage = this.stages[stageIndex];
        
        if (input.classList.contains('stage-notes')) {
            stage.notes = input.value;
        }
        
        this.onStageUpdate(stageIndex, stage);
    }
    
    goToNextStage() {
        if (this.currentStage < this.stages.length - 1) {
            this.currentStage++;
            this.render();
        }
    }
    
    goToPreviousStage() {
        if (this.currentStage > 0) {
            this.currentStage--;
            this.render();
        }
    }
    
    getProgressPercentage() {
        if (this.stages.length === 0) return 0;
        return (this.currentStage / this.stages.length) * 100;
    }
    
    addStage(stage) {
        this.stages.push(stage);
        this.render();
    }
    
    removeStage(index) {
        this.stages.splice(index, 1);
        if (this.currentStage >= this.stages.length) {
            this.currentStage = Math.max(0, this.stages.length - 1);
        }
        this.render();
    }
    
    getStagesData() {
        return this.stages;
    }
    
    setStages(stages) {
        this.stages = stages;
        this.currentStage = 0;
        this.render();
    }
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { DentalChart, TreatmentStages };
}